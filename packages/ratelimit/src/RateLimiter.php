<?php

declare(strict_types=1);

namespace Trunk\RateLimit;

use Trunk\Contracts\Clock;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Query\Raw;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;

/**
 * Counts events over a fixed time window, kept in the database so the count holds across requests,
 * processes and servers. Inject it to rate-limit anything a route or a job does: hash your own key
 * (an IP, a user id, an API key), pick a limit and a window, and call `assertBelow()` then `hit()`.
 * `RateLimitMiddleware` is the ready-made version of this for "limit this route by client address";
 * use this directly for anything more specific.
 *
 * An increment is one atomic UPDATE; creating a counter that a parallel request created first falls
 * back to that UPDATE, so two counters never race into an off-by-one.
 *
 * @api
 */
final readonly class RateLimiter
{
    /** @internal wired by the container, not part of the API */
    public function __construct(private Connection $connection, private string $table, private Clock $clock) {}

    /**
     * @throws HttpException 429 with Retry-After when the count for `$key` has reached `$limit`
     */
    public function assertBelow(string $key, int $limit, int $window): void
    {
        $row = $this->row($key, $window);

        if ($row !== null && $row['count'] >= $limit) {
            $retry = $row['start'] + $window - $this->clock->now();

            throw new HttpException(429, 'Too many requests. Try again later.', ['Retry-After' => (string) max(1, $retry)], null, ErrorCode::TooManyRequests->value);
        }
    }

    public function hit(string $key, int $window): void
    {
        $now = $this->clock->now();
        $live = $now - $window;

        if ($this->connection->table($this->table)->where('key_hash', '=', $key)->where('window_start', '>', $live)->update(['attempts' => new Raw('attempts + 1')]) > 0) {
            return;
        }

        $this->connection->table($this->table)->where('key_hash', '=', $key)->where('window_start', '<=', $live)->delete();

        try {
            $this->connection->table($this->table)->insert(['key_hash' => $key, 'attempts' => 1, 'window_start' => $now]);
        } catch (QueryException $e) {
            // A parallel request created the counter first: count on top of it.
            if ($this->connection->table($this->table)->where('key_hash', '=', $key)->update(['attempts' => new Raw('attempts + 1')]) === 0) {
                throw $e;
            }
        }
    }

    public function clear(string $key): void
    {
        $this->connection->table($this->table)->where('key_hash', '=', $key)->delete();
    }

    /**
     * Deletes every counter whose window is at least `$window` seconds old. Run from cron
     * (`trunk rate-limit:prune`); counters left alone still expire correctly on their own, this only
     * keeps the table small.
     */
    public function prune(int $window): int
    {
        return $this->connection->table($this->table)->where('window_start', '<=', $this->clock->now() - $window)->delete();
    }

    /**
     * @return array{count: int, start: int}|null the live window, if any
     */
    private function row(string $key, int $window): ?array
    {
        $row = $this->connection->table($this->table)->where('key_hash', '=', $key)->where('window_start', '>', $this->clock->now() - $window)->first();

        return $row === null || !is_numeric($row['attempts']) || !is_numeric($row['window_start']) ? null : ['count' => (int) $row['attempts'], 'start' => (int) $row['window_start']];
    }
}
