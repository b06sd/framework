<?php

declare(strict_types=1);

namespace Trunk\Foundation\Manifest;

use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;

/**
 * Checks a module list against the dependencies the modules declare: missing modules, wrong order
 * and cycles. Every problem carries the exact fix. Nothing is reordered automatically.
 */
final class ModuleGraph
{
    /**
     * @param list<string> $modules in manifest order (a name that is not a class is reported, not trusted)
     *
     * @return list<string>
     */
    public function errors(array $modules): array
    {
        $position = array_flip($modules);
        $edges = [];
        $errors = [];

        foreach ($modules as $class) {
            if (!class_exists($class)) {
                $errors[] = \sprintf('%s is listed in trunk.php but the class does not exist. Fix: install the package that provides it (composer install) or remove it from trunk.php.', $class);

                continue;
            }

            $module = new $class();

            if (!$module instanceof ModuleDependencies) {
                continue;
            }

            foreach ($module->requires() as $required) {
                $edges[$class][] = $required;

                if (!isset($position[$required])) {
                    $errors[] = \sprintf('%s requires %s, which is not in trunk.php. Fix: add \\%s::class above \\%s::class (or run `trunk package:install`).', $class, $required, ltrim($required, '\\'), ltrim($class, '\\'));
                }
            }

            foreach ($module->after() as $later) {
                if (isset($position[$later])) {
                    $edges[$class][] = $later;
                }
            }
        }

        $cycles = $this->cycles($edges);

        foreach ($cycles as $cycle) {
            $errors[] = 'Circular module dependency: ' . implode(' -> ', $cycle) . '. Fix: remove one of the requirements.';
        }

        if ($cycles !== []) {
            return $errors;
        }

        foreach ($edges as $class => $dependencies) {
            foreach ($dependencies as $dependency) {
                if (isset($position[$dependency]) && $position[$dependency] > $position[$class]) {
                    $errors[] = \sprintf('%s must be listed before %s in trunk.php. Fix: move \\%s::class above \\%s::class.', $dependency, $class, ltrim($dependency, '\\'), ltrim($class, '\\'));
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, list<string>> $edges
     *
     * @return list<list<string>>
     */
    private function cycles(array $edges): array
    {
        $found = [];
        $state = [];

        foreach (array_keys($edges) as $start) {
            $this->visit($start, $edges, $state, [], $found);
        }

        return $found;
    }

    /**
     * @param array<string, list<string>> $edges
     * @param array<string, int>          $state 1 = in progress, 2 = done
     * @param list<string>                $path
     * @param list<list<string>>          $found
     */
    private function visit(string $node, array $edges, array &$state, array $path, array &$found): void
    {
        if (($state[$node] ?? 0) === 2) {
            return;
        }

        if (($state[$node] ?? 0) === 1) {
            $found[] = [...\array_slice($path, (int) array_search($node, $path, true)), $node];

            return;
        }

        $state[$node] = 1;

        foreach ($edges[$node] ?? [] as $next) {
            $this->visit($next, $edges, $state, [...$path, $node], $found);
        }

        $state[$node] = 2;
    }
}
