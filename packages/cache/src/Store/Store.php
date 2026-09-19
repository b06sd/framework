<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

/**
 * Where cache entries live. Values are data only (scalars, null, arrays); the Cache validates that
 * before it reaches a store. Keys are already validated and prefixed.
 *
 * @api
 */
interface Store
{
    /**
     * @return array{mixed}|null a one-element array holding the value on a hit (so a cached null is not a miss), null on a miss or expired entry
     */
    public function read(string $key): ?array;

    /**
     * @param int|null $expiresAt absolute Unix timestamp, or null for "never"
     */
    public function write(string $key, mixed $value, ?int $expiresAt): bool;

    public function delete(string $key): bool;

    public function clear(): bool;
}
