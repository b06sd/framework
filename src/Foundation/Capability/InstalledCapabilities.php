<?php

declare(strict_types=1);

namespace Trunk\Foundation\Capability;

use JsonException;
use Trunk\Foundation\Exception\CapabilityException;

/**
 * Capabilities declared by installed Composer packages (`extra.trunk.capability`). Read only by the
 * CLI and the build, never on the request path. Invalid declarations are reported and ignored.
 */
final class InstalledCapabilities
{
    /**
     * @return array{list<Capability>, list<string>} capabilities and problems
     */
    public function read(string $projectBase): array
    {
        $vendor = realpath($projectBase . '/vendor');
        $file = $projectBase . '/vendor/composer/installed.json';

        if ($vendor === false || !is_file($file)) {
            return [[], []];
        }

        try {
            $installed = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [[], ['vendor/composer/installed.json is not valid JSON; run `composer install`.']];
        }

        $packages = \is_array($installed) ? ($installed['packages'] ?? $installed) : [];
        $capabilities = [];
        $problems = [];

        foreach (\is_array($packages) ? $packages : [] as $package) {
            $name = \is_array($package) && \is_string($package['name'] ?? null) ? $package['name'] : null;
            $metadata = \is_array($package) && \is_array($package['extra'] ?? null) && \is_array($package['extra']['trunk'] ?? null) ? ($package['extra']['trunk']['capability'] ?? null) : null;

            if ($name === null || $metadata === null) {
                continue;
            }

            $path = \is_string($package['install-path'] ?? null) ? realpath($vendor . '/composer/' . $package['install-path']) : false;

            if (!\is_array($metadata) || $path === false || !str_starts_with($path, $vendor . \DIRECTORY_SEPARATOR)) {
                $problems[] = \sprintf('Package "%s" declares a Trunk capability but its metadata or install path is invalid; ignored.', $name);

                continue;
            }

            try {
                $capabilities[] = Capability::fromMetadata($metadata, $name, $path);
            } catch (CapabilityException $e) {
                $problems[] = $e->getMessage() . ' Ignored.';
            }
        }

        return [$capabilities, $problems];
    }
}
