<?php

declare(strict_types=1);

namespace Trunk\Cache;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;
use Trunk\Cache\Exception\InvalidKeyException;
use Trunk\Cache\Store\Store;
use Trunk\Contracts\Clock;

/**
 * PSR-16 cache. Only data can be cached (scalars, null and arrays of them): objects are refused
 * by `set()` returning false, which keeps every store free of unserialization.
 */
final readonly class Cache implements CacheInterface
{
    public function __construct(
        private Store $store,
        private Clock $clock,
        private string $prefix = '',
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $hit = $this->store->read($this->key($key));

        return $hit === null ? $default : $hit[0];
    }

    public function set(string $key, mixed $value, int|DateInterval|null $ttl = null): bool
    {
        $internal = $this->key($key);
        $expiresAt = $this->expiry($ttl);

        // PSR-16: a zero or negative TTL means the item is already expired, so it is deleted.
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return $this->store->delete($internal);
        }

        return self::isData($value) && $this->store->write($internal, $value, $expiresAt);
    }

    public function delete(string $key): bool
    {
        return $this->store->delete($this->key($key));
    }

    public function clear(): bool
    {
        return $this->store->clear();
    }

    /**
     * @param iterable<mixed> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $values = [];

        foreach ($keys as $key) {
            $valid = $this->assertKey($key);
            $values[$valid] = $this->get($valid, $default);
        }

        return $values;
    }

    /**
     * @param iterable<mixed> $values key => value
     */
    public function setMultiple(iterable $values, int|DateInterval|null $ttl = null): bool
    {
        $ok = true;

        foreach ($values as $key => $value) {
            $ok = $this->set($this->assertKey($key), $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<mixed> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;

        foreach ($keys as $key) {
            $ok = $this->delete($this->assertKey($key)) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        return $this->store->read($this->key($key)) !== null;
    }

    /**
     * Returns the cached value, or computes, stores and returns it.
     *
     * @param Closure(): mixed $compute
     */
    public function remember(string $key, int|DateInterval|null $ttl, Closure $compute): mixed
    {
        $hit = $this->store->read($this->key($key));

        if ($hit !== null) {
            return $hit[0];
        }

        $value = $compute();
        $this->set($key, $value, $ttl);

        return $value;
    }

    private function key(string $key): string
    {
        $this->assertKey($key);

        return $this->prefix . $key;
    }

    private function assertKey(mixed $key): string
    {
        if (!\is_string($key) || preg_match('/^[A-Za-z0-9_.\-]{1,255}$/D', $key) !== 1) {
            throw new InvalidKeyException('A cache key must be 1-255 characters from A-Z a-z 0-9 _ . - (the characters { } ( ) / \\ @ : are reserved).');
        }

        return $key;
    }

    private function expiry(int|DateInterval|null $ttl): ?int
    {
        return match (true) {
            $ttl === null => null,
            \is_int($ttl) => $this->clock->now() + $ttl,
            default => new DateTimeImmutable('@' . $this->clock->now())->add($ttl)->getTimestamp(),
        };
    }

    private static function isData(mixed $value): bool
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                if (!self::isData($item)) {
                    return false;
                }
            }

            return true;
        }

        return match (true) {
            \is_float($value) => is_finite($value),
            \is_string($value) => preg_match('//u', $value) === 1,
            default => $value === null || \is_scalar($value),
        };
    }
}
