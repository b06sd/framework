<?php

declare(strict_types=1);

namespace Trunk\Foundation\Manifest;

use Trunk\Contracts\Module;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Support\ClassName;

/**
 * Ordered, validated list of module classes, normally loaded from a compiled PHP file.
 *
 * @api
 */
final readonly class ModuleManifest
{
    /** @var list<class-string<Module>> */
    public array $modules;

    /**
     * @param list<mixed> $modules
     */
    public function __construct(array $modules)
    {
        $validated = [];

        foreach ($modules as $class) {
            if (!\is_string($class) || !ClassName::isValid($class) || !is_subclass_of($class, Module::class)) {
                throw new ConfigurationException(\sprintf(
                    'Manifest entry %s is not a class implementing %s.',
                    \is_string($class) ? '"' . $class . '"' : get_debug_type($class),
                    Module::class,
                ));
            }

            $validated[] = $class;
        }

        $this->modules = $validated;
    }

    /**
     * @param non-empty-string $path
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new ConfigurationException(\sprintf('Module manifest "%s" does not exist.', $path));
        }

        $modules = require $path;

        if (!\is_array($modules) || !array_is_list($modules)) {
            throw new ConfigurationException(\sprintf('Module manifest "%s" must return a list.', $path));
        }

        return new self($modules);
    }
}
