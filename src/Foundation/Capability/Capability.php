<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use Trunk\Foundation\Exception\CapabilityException;
use Trunk\Support\ClassName;

/**
 * A named piece of functionality an application can enable: a set of modules plus the config,
 * environment settings and directories it needs. Metadata that comes from third-party packages is
 * untrusted, so `fromMetadata()` validates every field.
 */
final readonly class Capability
{
    /**
     * @param list<string>          $modules     module class names, in load order
     * @param list<string>          $requires    ids of capabilities that must also be enabled
     * @param array<string, string> $config      config name => absolute path of a PHP stub to publish as config/<name>.php
     * @param array<string, string> $env         setting => default value, added to .env and .env.example
     * @param list<string>          $directories project directories to create (each gets a .gitkeep)
     * @param array<string, string> $composer    Composer packages (or ext-* extensions) the capability's code needs beyond the core, name => constraint; built-in capabilities only, never read from third-party metadata
     * @param array<string, list<string>> $integrations capability id => modules that are added only while that other capability is also enabled (e.g. cache + console => cache:clear)
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $modules,
        public array $requires = [],
        public array $config = [],
        public array $env = [],
        public array $directories = [],
        public array $integrations = [],
        public string $package = 'trunkphp/framework',
        public array $composer = [],
    ) {}

    public function isBuiltIn(): bool
    {
        return $this->package === 'trunkphp/framework';
    }

    /**
     * Validates package-supplied metadata (`extra.trunk.capability` in composer.json).
     *
     * @param array<array-key, mixed> $metadata
     *
     * @throws CapabilityException
     */
    public static function fromMetadata(array $metadata, string $package, string $packageDirectory): self
    {
        $where = 'Package "' . $package . '"';
        $id = self::string($metadata, 'id', $where);
        $name = self::string($metadata, 'name', $where);
        $description = \is_string($metadata['description'] ?? null) ? $metadata['description'] : '';

        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $id) !== 1) {
            throw new CapabilityException($where . ': capability id "' . $id . '" must be lowercase letters, digits or "-" (max 32).');
        }

        if (preg_match('/^[^\x00-\x1F\x7F]{1,64}$/D', $name) !== 1 || preg_match('/^[^\x00-\x1F\x7F]{0,200}$/D', $description) !== 1) {
            throw new CapabilityException($where . ': name and description must be short single-line text.');
        }

        $modules = self::list($metadata, 'modules', $where);

        if ($modules === []) {
            throw new CapabilityException($where . ': a capability needs at least one module.');
        }

        foreach ($modules as $module) {
            if (!ClassName::isValid($module)) {
                throw new CapabilityException($where . ': "' . $module . '" is not a valid class name.');
            }
        }

        $requires = self::list($metadata, 'requires', $where);

        foreach ($requires as $required) {
            if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $required) !== 1) {
                throw new CapabilityException($where . ': required capability "' . $required . '" is not a valid id.');
            }
        }

        return new self(
            $id,
            $name,
            $description,
            $modules,
            $requires,
            self::config($metadata['config'] ?? [], $where, $packageDirectory),
            self::env($metadata['env'] ?? [], $where),
            self::directories($metadata['directories'] ?? [], $where),
            self::integrations($metadata['integrations'] ?? [], $where),
            $package,
        );
    }

    /**
     * @param array<array-key, mixed> $metadata
     */
    private static function string(array $metadata, string $key, string $where): string
    {
        $value = $metadata[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : throw new CapabilityException(\sprintf('%s: "%s" is required.', $where, $key));
    }

    /**
     * @param array<array-key, mixed> $metadata
     *
     * @return list<string>
     */
    private static function list(array $metadata, string $key, string $where): array
    {
        $value = $metadata[$key] ?? [];

        if (!\is_array($value) || !array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
            throw new CapabilityException(\sprintf('%s: "%s" must be a list of strings.', $where, $key));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function config(mixed $config, string $where, string $packageDirectory): array
    {
        if (!\is_array($config)) {
            throw new CapabilityException($where . ': "config" must be an object of name => file.');
        }

        $root = realpath($packageDirectory);
        $resolved = [];

        foreach ($config as $name => $relative) {
            $name = (string) $name;

            if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $name) !== 1 || !\is_string($relative) || !str_ends_with($relative, '.php')) {
                throw new CapabilityException($where . ': config entry "' . $name . '" must map a simple name to a .php file.');
            }

            $file = $root === false ? false : realpath($root . '/' . $relative);

            // The stub must be a real file inside the package, so metadata cannot point at other files.
            if ($root === false || $file === false || !str_starts_with($file, $root . \DIRECTORY_SEPARATOR) || !is_file($file)) {
                throw new CapabilityException($where . ': config stub "' . $relative . '" does not exist inside the package.');
            }

            $resolved[$name] = $file;
        }

        return $resolved;
    }

    /**
     * @return array<string, string>
     */
    private static function env(mixed $env, string $where): array
    {
        if (!\is_array($env)) {
            throw new CapabilityException($where . ': "env" must be an object of SETTING => default.');
        }

        $validated = [];

        foreach ($env as $key => $value) {
            $key = (string) $key;

            if (preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $key) !== 1 || !\is_string($value) || preg_match('/^[^\r\n\0]{0,200}$/D', $value) !== 1) {
                throw new CapabilityException($where . ': env setting "' . $key . '" must be UPPER_SNAKE_CASE with a single-line default.');
            }

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function integrations(mixed $integrations, string $where): array
    {
        if (!\is_array($integrations)) {
            throw new CapabilityException($where . ': "integrations" must be an object of capability id => list of modules.');
        }

        $validated = [];

        foreach ($integrations as $other => $modules) {
            $other = (string) $other;

            if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $other) !== 1 || !\is_array($modules) || !array_is_list($modules) || $modules === []) {
                throw new CapabilityException($where . ': integration "' . $other . '" must map a capability id to a list of modules.');
            }

            foreach ($modules as $module) {
                if (!\is_string($module) || !ClassName::isValid($module)) {
                    throw new CapabilityException($where . ': integration "' . $other . '" lists an invalid class name.');
                }

                $validated[$other][] = $module;
            }
        }

        return $validated;
    }

    /**
     * @return list<string>
     */
    private static function directories(mixed $directories, string $where): array
    {
        if (!\is_array($directories) || !array_is_list($directories)) {
            throw new CapabilityException($where . ': "directories" must be a list.');
        }

        $validated = [];

        foreach ($directories as $directory) {
            if (!\is_string($directory) || preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $directory) !== 1) {
                throw new CapabilityException($where . ': directory entries must be simple relative paths.');
            }

            $validated[] = $directory;
        }

        return $validated;
    }
}
