<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

use Predis\Client;
use Trunk\Cache\Exception\CacheException;
use Trunk\Contracts\Clock;
use Trunk\Foundation\Configuration;

/**
 * Chooses the store from configuration. Registered as a declarative factory, so the compiled
 * container calls it directly. `cache.redis.*` is read here, lazily, only when the driver is
 * actually "redis": a project whose config predates the Redis store, and so has no `redis` block
 * at all, keeps working on every other driver.
 */
final readonly class StoreFactory
{
    public function __construct(private Clock $clock) {}

    public function make(string $driver, string $path, string $prefix, Configuration $configuration): Store
    {
        return match ($driver) {
            'file' => new FileStore($path, $this->clock),
            'array' => new ArrayStore($this->clock),
            'null' => new NullStore(),
            'redis' => new RedisStore($this->redisClient($configuration), $this->clock, $prefix),
            default => throw new CacheException(\sprintf('cache.driver must be "file", "array", "null" or "redis", "%s" given. Set CACHE_DRIVER in .env.', $driver)),
        };
    }

    private function redisClient(Configuration $configuration): Client
    {
        if (!class_exists(Client::class)) {
            throw new CacheException('cache.driver is "redis", but predis/predis is not installed. Run `composer require predis/predis`.');
        }

        $password = $this->setting($configuration, 'cache.redis.password', '');

        return new Client(array_filter([
            'scheme' => 'tcp',
            'host' => $this->setting($configuration, 'cache.redis.host', '127.0.0.1'),
            'port' => (int) $this->setting($configuration, 'cache.redis.port', 6379),
            'password' => $password === '' ? null : $password,
            'database' => (int) $this->setting($configuration, 'cache.redis.database', 0),
        ], static fn(mixed $value): bool => $value !== null));
    }

    private function setting(Configuration $configuration, string $key, string|int $default): string|int
    {
        if (!$configuration->has($key)) {
            return $default;
        }

        return \is_int($default) ? $configuration->int($key) : $configuration->string($key);
    }
}
