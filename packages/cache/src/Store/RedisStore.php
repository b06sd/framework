<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

use JsonException;
use Predis\ClientInterface;
use Trunk\Contracts\Clock;

/**
 * Redis store: the same cache is then correct across every server, unlike `file` or `array`, which
 * are only correct on one box. Entries are JSON (never PHP's native serialization), values expire in
 * Redis itself (`SET ... EX`), and `clear()` only ever removes keys under this store's own prefix
 * (`SCAN` and delete), never `FLUSHDB` — a shared Redis instance is safe to point more than one thing at.
 */
final readonly class RedisStore implements Store
{
    public function __construct(
        private ClientInterface $client,
        private Clock $clock,
        private string $prefix = '',
    ) {}

    public function read(string $key): ?array
    {
        $raw = $this->client->get($key);

        if (!\is_string($raw)) {
            return null;
        }

        try {
            return [json_decode($raw, true, 128, \JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            $this->delete($key);

            return null;
        }
    }

    public function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        try {
            $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION, 128);
        } catch (JsonException) {
            // Not representable as data (invalid UTF-8, INF/NAN, too deep): refuse rather than corrupt.
            return false;
        }

        if ($expiresAt === null) {
            $this->client->set($key, $json);

            return true;
        }

        $seconds = max(1, $expiresAt - $this->clock->now());
        $this->client->set($key, $json, 'EX', $seconds);

        return true;
    }

    public function delete(string $key): bool
    {
        $this->client->del([$key]);

        return true;
    }

    /**
     * Removes only the keys under this store's own prefix (every key it could have written), never
     * the whole database.
     */
    public function clear(): bool
    {
        $cursor = 0;
        $pattern = $this->prefix . '*';

        do {
            /** @var array{0: string, 1: list<string>} $result */
            $result = $this->client->scan($cursor, ['match' => $pattern, 'count' => 1000]);
            [$cursor, $keys] = $result;
            $cursor = (int) $cursor;

            if ($keys !== []) {
                $this->client->del($keys);
            }
        } while ($cursor !== 0);

        return true;
    }
}
