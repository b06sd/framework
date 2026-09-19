<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use JsonException;

/**
 * The Composer packages and PHP extensions the built-in capabilities need beyond the core, and a
 * check of what a project actually has installed. The core distribution is minimal on purpose, so
 * a project that enables `http` must also install the PSR HTTP interfaces; this is where that is
 * worked out for `trunk new`, `package:install`, `build` and `doctor`.
 */
final class ComposerRequirements
{
    /**
     * @param iterable<Capability> $capabilities
     *
     * @return array<string, string> package or extension => constraint, sorted
     */
    public function for(iterable $capabilities): array
    {
        $requirements = [];

        foreach ($capabilities as $capability) {
            $requirements = [...$requirements, ...$capability->composer];
        }

        ksort($requirements);

        return $requirements;
    }

    /**
     * What is required but not present: packages absent from vendor/composer/installed.json, and
     * extensions that are not loaded.
     *
     * @param array<string, string> $requirements
     *
     * @return array<string, string>
     */
    public function missing(string $projectBase, array $requirements): array
    {
        $installed = null;
        $missing = [];

        foreach ($requirements as $name => $constraint) {
            if (str_starts_with($name, 'ext-')) {
                if (!\extension_loaded(substr($name, 4))) {
                    $missing[$name] = $constraint;
                }

                continue;
            }

            $installed ??= $this->installed($projectBase);

            if (!isset($installed[$name])) {
                $missing[$name] = $constraint;
            }
        }

        return $missing;
    }

    /**
     * The `composer require` arguments for the missing packages (extensions cannot be installed by
     * Composer, so they are not included).
     *
     * @param array<string, string> $missing
     *
     * @return list<string> e.g. "psr/http-message:^2.0"
     */
    public function specs(array $missing): array
    {
        $specs = [];

        foreach ($missing as $name => $constraint) {
            if (!str_starts_with($name, 'ext-')) {
                $specs[] = $name . ':' . $constraint;
            }
        }

        return $specs;
    }

    /**
     * @param array<string, string> $missing
     */
    public function describe(array $missing): string
    {
        $extensions = array_keys(array_filter($missing, static fn(string $_, string $name): bool => str_starts_with($name, 'ext-'), \ARRAY_FILTER_USE_BOTH));
        $specs = $this->specs($missing);
        $parts = [];

        if ($specs !== []) {
            $parts[] = 'missing Composer packages; run `composer require ' . implode(' ', $specs) . '`';
        }

        if ($extensions !== []) {
            $parts[] = 'missing PHP extensions: ' . implode(', ', array_map(static fn(string $e): string => substr($e, 4), $extensions));
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, true> installed package names (including what they replace)
     */
    private function installed(string $projectBase): array
    {
        $file = $projectBase . '/vendor/composer/installed.json';

        if (!is_file($file)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        $packages = \is_array($data) ? ($data['packages'] ?? $data) : [];
        $names = [];

        foreach (\is_array($packages) ? $packages : [] as $package) {
            if (!\is_array($package)) {
                continue;
            }

            if (\is_string($package['name'] ?? null)) {
                $names[$package['name']] = true;
            }

            foreach (\is_array($package['replace'] ?? null) ? array_keys($package['replace']) : [] as $replaced) {
                $names[(string) $replaced] = true;
            }
        }

        return $names;
    }
}
