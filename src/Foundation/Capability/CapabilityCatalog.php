<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use Trunk\Foundation\Project\Project;

/**
 * Every capability the project could use: the built-ins plus what installed packages declare.
 * A package cannot redefine a built-in id.
 */
final class CapabilityCatalog
{
    /** @var array<string, Capability> */
    private array $capabilities = [];

    /** @var list<string> */
    private array $problems;

    public function __construct(?string $projectBase, BuiltInCapabilities $builtIn = new BuiltInCapabilities(), InstalledCapabilities $installed = new InstalledCapabilities())
    {
        foreach ($builtIn->all() as $capability) {
            $this->capabilities[$capability->id] = $capability;
        }

        [$found, $this->problems] = $projectBase === null ? [[], []] : $installed->read($projectBase);

        foreach ($found as $capability) {
            if (isset($this->capabilities[$capability->id])) {
                $this->problems[] = \sprintf('Package "%s" declares capability "%s", which already exists (%s); ignored.', $capability->package, $capability->id, $this->capabilities[$capability->id]->package);

                continue;
            }

            $this->capabilities[$capability->id] = $capability;
        }
    }

    /**
     * @return list<Capability>
     */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    public function find(string $id): ?Capability
    {
        return $this->capabilities[$id] ?? null;
    }

    /**
     * The capability declared by a Composer package, if any.
     */
    public function forPackage(string $package): ?Capability
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->package === $package) {
                return $capability;
            }
        }

        return null;
    }

    /**
     * @return list<string> problems found while reading package metadata
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Capabilities all of whose modules are listed in trunk.php.
     *
     * @param list<string> $modules
     *
     * @return list<Capability>
     */
    public function enabled(array $modules): array
    {
        return array_values(array_filter($this->capabilities, static fn(Capability $c): bool => array_diff($c->modules, $modules) === []));
    }

    /**
     * Capabilities with only some of their modules listed (a hand-editing mistake).
     *
     * @param list<string> $modules
     *
     * @return list<Capability>
     */
    public function partiallyEnabled(array $modules): array
    {
        return array_values(array_filter($this->capabilities, static fn(Capability $c): bool => array_diff($c->modules, $modules) !== [] && array_intersect($c->modules, $modules) !== []));
    }

    /**
     * Enabled capabilities whose requirements are not enabled: [capability, missing id] pairs.
     *
     * @param list<string> $modules
     *
     * @return list<array{Capability, string}>
     */
    public function missingRequirements(array $modules): array
    {
        $enabledIds = array_map(static fn(Capability $c): string => $c->id, $this->enabled($modules));
        $missing = [];

        foreach ($this->enabled($modules) as $capability) {
            foreach ($capability->requires as $required) {
                if (!\in_array($required, $enabledIds, true)) {
                    $missing[] = [$capability, $required];
                }
            }
        }

        return $missing;
    }

    /**
     * Every module any capability adds only as an integration with another capability.
     *
     * @return list<string>
     */
    public function allIntegrationModules(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(static fn(Capability $c): array => array_merge([], ...array_values($c->integrations)), $this->all()))));
    }

    /**
     * The integration modules that should be listed, given which capabilities are enabled: a
     * capability's integration with another applies only while both are enabled.
     *
     * @param list<string> $modules
     *
     * @return list<string>
     */
    public function integrationModules(array $modules): array
    {
        $enabled = $this->enabled($modules);
        $enabledIds = array_map(static fn(Capability $c): string => $c->id, $enabled);
        $wanted = [];

        foreach ($enabled as $capability) {
            foreach ($capability->integrations as $other => $integrationModules) {
                if (\in_array($other, $enabledIds, true)) {
                    $wanted = [...$wanted, ...$integrationModules];
                }
            }
        }

        return array_values(array_unique($wanted));
    }

    /**
     * Modules of the project that no capability claims (normally just the application's own module).
     *
     * @param list<string> $modules
     *
     * @return list<string>
     */
    public function unclaimedModules(array $modules): array
    {
        $claimed = [...array_merge(...array_map(static fn(Capability $c): array => $c->modules, $this->all())), ...$this->allIntegrationModules()];

        return array_values(array_diff($modules, $claimed));
    }
}
