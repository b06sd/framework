<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use Trunk\Foundation\Exception\CapabilityException;

/**
 * Works out what enabling or disabling a capability involves.
 */
final readonly class CapabilityResolver
{
    public function __construct(private CapabilityCatalog $catalog) {}

    /**
     * The capabilities to enable, requirements first, skipping those already enabled.
     *
     * @param list<string> $modules the project's current modules
     *
     * @return list<Capability>
     *
     * @throws CapabilityException
     */
    public function plan(string $id, array $modules): array
    {
        $plan = [];
        $this->visit($id, $modules, [], $plan);

        return array_values($plan);
    }

    /**
     * Enabled capabilities that require `$id` (they must be removed first).
     *
     * @param list<string> $modules
     *
     * @return list<Capability>
     */
    public function dependents(string $id, array $modules): array
    {
        return array_values(array_filter($this->catalog->enabled($modules), static fn(Capability $c): bool => \in_array($id, $c->requires, true)));
    }

    /**
     * @param list<string>            $modules
     * @param list<string>            $path
     * @param array<string, Capability> $plan
     */
    private function visit(string $id, array $modules, array $path, array &$plan): void
    {
        if (\in_array($id, $path, true)) {
            throw new CapabilityException('Circular capability requirements: ' . implode(' -> ', [...$path, $id]) . '.');
        }

        $capability = $this->catalog->find($id) ?? throw new CapabilityException(\sprintf(
            'There is no capability "%s".%s Run `trunk package:list` to see what is available.',
            $id,
            $path === [] ? '' : \sprintf(' It is required by "%s".', $path[array_key_last($path)]),
        ));

        if (array_diff($capability->modules, $modules) === [] || isset($plan[$id])) {
            return;
        }

        foreach ($capability->requires as $required) {
            $this->visit($required, $modules, [...$path, $id], $plan);
        }

        $plan[$id] = $capability;
    }
}
