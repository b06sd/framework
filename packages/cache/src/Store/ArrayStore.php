<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

use Trunk\Contracts\Clock;

/**
 * In-memory store for the lifetime of the process (tests, development, per-request memoisation).
 */
final class ArrayStore implements Store
{
    /** @var array<string, array{mixed, int|null}> */
    private array $entries = [];

    public function __construct(private readonly Clock $clock) {}

    public function read(string $key): ?array
    {
        if (!isset($this->entries[$key])) {
            return null;
        }

        [$value, $expiresAt] = $this->entries[$key];

        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            unset($this->entries[$key]);

            return null;
        }

        return [$value];
    }

    public function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        $this->entries[$key] = [$value, $expiresAt];

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->entries[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->entries = [];

        return true;
    }
}
