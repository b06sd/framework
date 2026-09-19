<?php

declare(strict_types=1);

namespace Trunk\Foundation\Project;

use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Exception\ProjectException;
use Trunk\Foundation\Manifest\ModuleManifest;

/**
 * Reads and validates `trunk.php`:
 *
 *   return ['name' => 'crm', 'type' => 'web', 'modules' => [HttpModule::class, App\AppModule::class]];
 *
 * The module list is the application's capability list.
 */
final class ProjectLoader
{
    public function isProject(string $basePath): bool
    {
        return is_file($basePath . '/trunk.php');
    }

    /**
     * Walks up from `$directory` until a `trunk.php` is found.
     */
    public function locate(string $directory): ?string
    {
        $current = realpath($directory);

        while ($current !== false) {
            if ($this->isProject($current)) {
                return $current;
            }

            $parent = \dirname($current);

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return null;
    }

    public function load(string $basePath): Project
    {
        $file = $basePath . '/trunk.php';

        if (!is_file($file)) {
            throw new ProjectException(\sprintf('"%s" is not a Trunk project: there is no trunk.php. Create one with `trunk new`.', $basePath));
        }

        $manifest = require $file;

        if (!\is_array($manifest)) {
            throw new ProjectException('trunk.php must return an array with "name", "type" and "modules".');
        }

        $name = $manifest['name'] ?? null;
        $type = $manifest['type'] ?? null;
        $modules = $manifest['modules'] ?? null;

        if (!\is_string($name) || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $name) !== 1) {
            throw new ProjectException('trunk.php: "name" must be lowercase letters, digits, "-" or "_".');
        }

        if (!\is_string($type) || preg_match('/^[a-z][a-z-]*$/D', $type) !== 1) {
            throw new ProjectException('trunk.php: "type" must be a lowercase word such as api, web, cli.');
        }

        if (!\is_array($modules) || !array_is_list($modules)) {
            throw new ProjectException('trunk.php: "modules" must be a list of module class names.');
        }

        try {
            $validated = new ModuleManifest($modules)->modules;
        } catch (ConfigurationException $e) {
            throw new ProjectException('trunk.php: ' . $e->getMessage() . ' Check the class name and that Composer autoloading is up to date (`composer dump-autoload`).', 0, $e);
        }

        return new Project($basePath, $name, $type, $validated);
    }
}
