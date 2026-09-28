<?php

declare(strict_types=1);

namespace Trunk\Foundation\Manifest;

use Throwable;
use Trunk\Contracts\Module;
use Trunk\Foundation\Exception\ConfigurationException;

/**
 * Turns module class-strings into modules, the same way everywhere. ModuleManifest already
 * guarantees every class-string here exists and implements Module (validated once, when trunk.php
 * is loaded), so the only thing left to go wrong is a constructor that does real work and throws;
 * this is the one place that catches it and says which module failed instead of failing however
 * the calling code's own exception handling (or lack of it) happens to react.
 *
 * @internal
 */
final class ModuleInstances
{
    /**
     * @param list<class-string<Module>> $classes
     *
     * @return list<Module>
     */
    public static function from(array $classes): array
    {
        return array_map(self::one(...), $classes);
    }

    /**
     * @param class-string<Module> $class
     */
    public static function one(string $class): Module
    {
        try {
            return new $class();
        } catch (Throwable $e) {
            throw new ConfigurationException(\sprintf('%s could not be constructed: %s', $class, $e->getMessage()), 0, $e);
        }
    }
}
