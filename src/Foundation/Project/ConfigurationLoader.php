<?php

declare(strict_types=1);

namespace Trunk\Foundation\Project;

use Closure;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\EnvSecret;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Runtime;

/**
 * Loads `config/<name>.php` files into one Configuration keyed by file name. A config file returns
 * an array, or `static fn (Runtime $runtime): array` when values depend on the environment. This
 * directory is only read in development and by `trunk build`; production loads the compiled
 * `build/config.php` instead.
 */
final class ConfigurationLoader
{
    public function fromDirectory(Project $project, Runtime $runtime): Configuration
    {
        return new Configuration($this->evaluate($project, $runtime), $runtime->variables);
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    public function evaluate(Project $project, Runtime $runtime): array
    {
        $items = [];
        $files = glob($project->configDirectory() . '/*.php');

        foreach ($files === false ? [] : $files as $file) {
            $name = basename($file, '.php');
            $value = require $file;
            $value = $value instanceof Closure ? $value($runtime) : $value;

            if (!\is_array($value)) {
                throw new ProjectException(\sprintf('config/%s.php must return an array (or a closure returning one).', $name));
            }

            $this->assertPlain($value, 'config/' . $name . '.php');
            $items[$name] = $value;
        }

        ksort($items);

        return $items;
    }

    /**
     * Configuration must consist of scalars, null, arrays and secret references so it can be compiled into a plain file.
     *
     * @param array<array-key, mixed> $value
     */
    private function assertPlain(array $value, string $where): void
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $this->assertPlain($item, $where);
            } elseif ($item !== null && !\is_scalar($item) && !$item instanceof EnvSecret) {
                throw new ProjectException(\sprintf('%s: the value for "%s" is a %s; configuration may only contain scalars, null, arrays and $runtime->secret() references.', $where, (string) $key, get_debug_type($item)));
            }
        }
    }
}
