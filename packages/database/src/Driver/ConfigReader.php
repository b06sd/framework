<?php

declare(strict_types=1);

namespace Trunk\Database\Driver;

use Trunk\Database\Exception\ConnectionException;

/**
 * Reads and validates connection settings. Errors name the setting, never its value.
 */
final class ConfigReader
{
    /**
     * @param array<string, mixed> $config
     */
    public function string(array $config, string $key, string $pattern, string $hint, ?string $default = null): string
    {
        $value = $config[$key] ?? $default;

        if (!\is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new ConnectionException(\sprintf('Database setting "%s" is missing or invalid: %s', $key, $hint));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function port(array $config, int $default): int
    {
        $port = $config['port'] ?? $default;
        $port = \is_string($port) && ctype_digit($port) ? (int) $port : $port;

        if (!\is_int($port) || $port < 1 || $port > 65535) {
            throw new ConnectionException('Database setting "port" must be a number between 1 and 65535.');
        }

        return $port;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function optionalString(array $config, string $key): ?string
    {
        $value = $config[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return \is_string($value) && !str_contains($value, "\0") ? $value : throw new ConnectionException(\sprintf('Database setting "%s" must be a string.', $key));
    }
}
