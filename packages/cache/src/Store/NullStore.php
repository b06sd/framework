<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

/**
 * Stores nothing; every read is a miss. Handy to switch caching off without changing code.
 */
final class NullStore implements Store
{
    public function read(string $key): ?array
    {
        return null;
    }

    public function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        return true;
    }

    public function delete(string $key): bool
    {
        return true;
    }

    public function clear(): bool
    {
        return true;
    }
}
