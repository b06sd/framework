<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

use Trunk\Cache\Exception\CacheException;
use Trunk\Contracts\Clock;

/**
 * Chooses the store from configuration. Registered as a declarative factory, so the compiled
 * container calls it directly.
 */
final readonly class StoreFactory
{
    public function __construct(private Clock $clock) {}

    public function make(string $driver, string $path): Store
    {
        return match ($driver) {
            'file' => new FileStore($path, $this->clock),
            'array' => new ArrayStore($this->clock),
            'null' => new NullStore(),
            default => throw new CacheException(\sprintf('cache.driver must be "file", "array" or "null", "%s" given. Set CACHE_DRIVER in .env.', $driver)),
        };
    }
}
