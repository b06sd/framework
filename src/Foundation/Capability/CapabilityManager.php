<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use Trunk\Contracts\ModuleDependencies;
use Trunk\Foundation\Exception\CapabilityException;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Project\ProjectManifestEditor;

/**
 * Enables and disables capabilities in a project: resolves requirements, publishes what is needed
 * and rewrites the module list. The module list is written last, so a failure leaves it unchanged.
 */
final readonly class CapabilityManager
{
    public function __construct(
        private ProjectManifestEditor $editor = new ProjectManifestEditor(),
        private CapabilityPublisher $publisher = new CapabilityPublisher(),
    ) {}

    public function catalog(Project $project): CapabilityCatalog
    {
        return new CapabilityCatalog($project->basePath);
    }

    /**
     * @return list<string> what was done
     *
     * @throws CapabilityException
     */
    public function enable(Project $project, string $id): array
    {
        $catalog = $this->catalog($project);
        $plan = new CapabilityResolver($catalog)->plan($id, $project->modules);

        if ($plan === []) {
            return [\sprintf('"%s" is already enabled.', $id)];
        }

        $changes = [];
        $added = [];

        foreach ($plan as $capability) {
            $changes[] = \sprintf('Enabled %s%s.', $capability->name, $capability->id === $id ? '' : ' (required)');
            $changes = [...$changes, ...$this->publisher->publish($project, $capability)];
            $added = [...$added, ...$capability->modules];
        }

        $modules = $this->merge($catalog, $project->modules, $added);
        [$reconciled, $notes] = $this->reconcile($catalog, $modules);
        $this->save($project, $reconciled, [...$added, ...array_values(array_diff($reconciled, $modules))]);

        return [...$changes, ...$notes];
    }

    /**
     * @return list<string> what was done
     *
     * @throws CapabilityException
     */
    public function disable(Project $project, string $id): array
    {
        $catalog = $this->catalog($project);
        $capability = $catalog->find($id) ?? throw new CapabilityException(\sprintf('There is no capability "%s". Run `trunk package:list` to see what is available.', $id));

        if (!\in_array($capability, $catalog->enabled($project->modules), true)) {
            return [\sprintf('"%s" is not enabled.', $id)];
        }

        $dependents = new CapabilityResolver($catalog)->dependents($id, $project->modules);

        if ($dependents !== []) {
            throw new CapabilityException(\sprintf(
                'Cannot remove "%s": %s require%s it. Remove %s first (trunk package:remove %s).',
                $id,
                implode(', ', array_map(static fn(Capability $c): string => '"' . $c->id . '"', $dependents)),
                \count($dependents) === 1 ? 's' : '',
                \count($dependents) === 1 ? 'it' : 'them',
                $dependents[0]->id,
            ));
        }

        [$reconciled, $notes] = $this->reconcile($catalog, array_values(array_diff($project->modules, $capability->modules)));
        $this->save($project, $reconciled, []);

        return [
            \sprintf('Disabled %s.', $capability->name),
            ...$notes,
            'Its config file and .env settings were left in place; delete them if you no longer need them.',
        ];
    }

    /**
     * Brings the integration modules in line with what is enabled: adds the ones that should be
     * there (cache + console => cache:clear) and drops the ones whose pair is no longer complete.
     *
     * @return list<string> what was done
     */
    public function sync(Project $project): array
    {
        $catalog = $this->catalog($project);
        [$reconciled, $notes] = $this->reconcile($catalog, $project->modules);

        if ($reconciled === $project->modules) {
            return ['Integrations are already in sync.'];
        }

        $this->save($project, $reconciled, array_values(array_diff($reconciled, $project->modules)));

        return $notes;
    }

    /**
     * @param list<string> $modules
     *
     * @return array{list<string>, list<string>} the reconciled module list and notes about what changed
     */
    private function reconcile(CapabilityCatalog $catalog, array $modules): array
    {
        $wanted = $catalog->integrationModules($modules);
        $all = $catalog->allIntegrationModules();
        $notes = [];

        foreach (array_diff($modules, $wanted) as $module) {
            if (\in_array($module, $all, true)) {
                $notes[] = 'Removed integration module ' . $module . ' (its capabilities are no longer both enabled).';
            }
        }

        foreach (array_diff($wanted, $modules) as $module) {
            $notes[] = 'Added integration module ' . $module . '.';
        }

        $kept = array_values(array_filter($modules, static fn(string $m): bool => !\in_array($m, $all, true) || \in_array($m, $wanted, true)));
        $own = $catalog->unclaimedModules($kept);
        $claimed = array_values(array_diff($kept, $own));

        return [[...$claimed, ...array_values(array_diff($wanted, $claimed)), ...$own], $notes];
    }

    /**
     * New modules go after the other capability modules and before the application's own module.
     *
     * @param list<string> $current
     * @param list<string> $added
     *
     * @return list<string>
     */
    private function merge(CapabilityCatalog $catalog, array $current, array $added): array
    {
        $own = $catalog->unclaimedModules($current);
        $claimed = array_values(array_diff($current, $own));

        foreach (array_values(array_diff($added, $claimed)) as $module) {
            array_splice($claimed, $this->positionFor($module, $claimed), 0, [$module]);
        }

        return [...$claimed, ...$own];
    }

    /**
     * Where a new module goes: above the first listed module that declares it as a dependency
     * (so the manifest stays valid), otherwise at the end.
     *
     * @param list<string> $claimed
     */
    private function positionFor(string $module, array $claimed): int
    {
        foreach ($claimed as $index => $listed) {
            if (!class_exists($listed) || !is_subclass_of($listed, ModuleDependencies::class)) {
                continue;
            }

            $dependencies = new $listed();

            if (\in_array($module, $dependencies->requires(), true) || \in_array($module, $dependencies->after(), true)) {
                return $index;
            }
        }

        return \count($claimed);
    }

    /**
     * @param list<string> $modules
     * @param list<string> $added   only used to tell the user what to add by hand
     */
    private function save(Project $project, array $modules, array $added): void
    {
        try {
            $this->editor->write($project->basePath, $modules);
        } catch (ProjectException $e) {
            throw new CapabilityException('Could not edit trunk.php automatically: ' . $e->getMessage() . '.' . ($added === [] ? ' Remove the capability\'s modules from the list yourself.' : " Add these to the 'modules' list yourself:\n    " . implode("\n    ", array_map(static fn(string $m): string => '\\' . $m . '::class,', $added))), 0, $e);
        }
    }
}
