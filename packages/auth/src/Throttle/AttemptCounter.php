<?php

declare(strict_types=1);

namespace Trunk\Auth\Throttle;

use Trunk\Auth\Settings\ThrottleSettings;
use Trunk\Contracts\Clock;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Query\Raw;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;

/**
 * Counters over a fixed time window, kept in the database so they hold across requests, processes and
 * servers. Only hashes of the keys are stored. An increment is one atomic UPDATE; creating a counter
 * that a parallel request created first falls back to that UPDATE.
 */
final readonly class AttemptCounter
{
    public function __construct(private Connection $connection, private ThrottleSettings $settings, private Clock $clock) {}

    /**
     * @param list<array{string, int}> $limits pairs of counter key and the count that is already too many
     *
     * @throws HttpException 429 with Retry-After when any counter has reached its limit
     */
    public function assertBelow(array $limits): void
    {
        $retry = 0;

        foreach ($limits as [$key, $limit]) {
            $row = $this->row($key);

            if ($row !== null && $row['attempts'] >= $limit) {
                $retry = max($retry, $row['start'] + $this->settings->window - $this->clock->now());
            }
        }

        if ($retry > 0) {
            throw new HttpException(429, 'Too many attempts. Try again later.', ['Retry-After' => (string) $retry], null, ErrorCode::TooManyRequests->value);
        }
    }

    public function hit(string $key): void
    {
        $now = $this->clock->now();
        $table = $this->settings->table;
        $live = $now - $this->settings->window;

        if ($this->connection->table($table)->where('key_hash', '=', $key)->where('window_start', '>', $live)->update(['attempts' => new Raw('attempts + 1')]) > 0) {
            return;
        }

        $this->connection->table($table)->where('key_hash', '=', $key)->where('window_start', '<=', $live)->delete();

        try {
            $this->connection->table($table)->insert(['key_hash' => $key, 'attempts' => 1, 'window_start' => $now]);
        } catch (QueryException $e) {
            // A parallel request created the counter first: count on top of it.
            if ($this->connection->table($table)->where('key_hash', '=', $key)->update(['attempts' => new Raw('attempts + 1')]) === 0) {
                throw $e;
            }
        }
    }

    public function clear(string $key): void
    {
        $this->connection->table($this->settings->table)->where('key_hash', '=', $key)->delete();
    }

    public function prune(): int
    {
        return $this->connection->table($this->settings->table)->where('window_start', '<=', $this->clock->now() - $this->settings->window)->delete();
    }

    /**
     * @return array{attempts: int, start: int}|null the live window, if any
     */
    private function row(string $key): ?array
    {
        $row = $this->connection->table($this->settings->table)->where('key_hash', '=', $key)->where('window_start', '>', $this->clock->now() - $this->settings->window)->first();

        return $row === null || !is_numeric($row['attempts']) || !is_numeric($row['window_start']) ? null : ['attempts' => (int) $row['attempts'], 'start' => (int) $row['window_start']];
    }
}
