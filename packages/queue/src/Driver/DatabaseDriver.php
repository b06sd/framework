<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

use Trunk\Database\Connection\Connection;
use Trunk\Database\Query\Identifier;
use Trunk\Database\Query\QueryBuilder;

/**
 * Stores jobs in two tables through the query builder (bindings only). Claiming is race-safe on
 * SQLite, MySQL and PostgreSQL without SKIP LOCKED: read a few candidates, then claim one with a
 * conditional UPDATE and require that exactly one row changed. The UPDATE is atomic, so two
 * workers can never claim the same job; the loser simply tries the next candidate.
 */
final readonly class DatabaseDriver implements QueueDriver
{
    private const int CANDIDATES = 5;

    public function __construct(
        private Connection $connection,
        private string $table = 'trunk_jobs',
        private string $failedTable = 'trunk_failed_jobs',
    ) {
        Identifier::assertSimple($table);
        Identifier::assertSimple($failedTable);
    }

    public function push(string $queue, string $job, string $payload, int $availableAt, int $now, ?string $origin = null): int|string
    {
        return $this->connection->table($this->table)->insertGetId($this->row($queue, $job, $payload, $availableAt, $now, $origin));
    }

    public function pushMany(array $jobs, int $now): int
    {
        if ($jobs === []) {
            return 0;
        }

        return $this->connection->table($this->table)->insert(array_map(fn(array $j): array => $this->row($j['queue'], $j['job'], $j['payload'], $j['availableAt'], $now, $j['origin'] ?? null), $jobs));
    }

    public function reserve(array $queues, int $now, int $visibilityTimeout, string $worker): ?ReservedJob
    {
        $cutoff = $now - $visibilityTimeout;
        $claimable = static fn(QueryBuilder $group): QueryBuilder => $group->whereNull('reserved_at')->orWhere('reserved_at', '<=', $cutoff);

        foreach ($queues as $queue) {
            $candidates = $this->connection->table($this->table)
                ->select('id', 'job', 'payload', 'attempts', 'origin')
                ->where('queue', $queue)
                ->where('available_at', '<=', $now)
                ->where($claimable)
                ->orderBy('id')
                ->limit(self::CANDIDATES)
                ->get();

            foreach ($candidates as $row) {
                $id = $row['id'] ?? null;
                $job = $row['job'] ?? null;
                $payload = $row['payload'] ?? null;
                $attempts = $row['attempts'] ?? null;

                if (!(\is_int($id) || \is_string($id)) || !\is_string($job) || !\is_string($payload) || !is_numeric($attempts)) {
                    continue;
                }

                $claimed = $this->connection->table($this->table)
                    ->where('id', $id)
                    ->where('attempts', (int) $attempts)
                    ->where($claimable)
                    ->update(['reserved_at' => $now, 'reserved_by' => $worker, 'attempts' => (int) $attempts + 1]);

                if ($claimed === 1) {
                    $origin = $row['origin'] ?? null;

                    return new ReservedJob($id, $queue, $job, $payload, (int) $attempts + 1, $worker, \is_string($origin) ? $origin : null);
                }
            }
        }

        return null;
    }

    public function complete(ReservedJob $job): bool
    {
        return $this->owned($job)->delete() === 1;
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        return $this->owned($job)->update(['reserved_at' => null, 'reserved_by' => null, 'available_at' => $availableAt]) === 1;
    }

    public function fail(ReservedJob $job, string $exception, ?string $message, int $now): void
    {
        $this->connection->transaction(function () use ($job, $exception, $message, $now): void {
            if ($this->owned($job)->delete() !== 1) {
                return;
            }

            $this->connection->table($this->failedTable)->insert([
                'queue' => $job->queue,
                'job' => $job->job,
                'payload' => $job->payload,
                'exception' => substr($exception, 0, 255),
                'message' => $message === null ? null : substr($message, 0, 2000),
                'failed_at' => $now,
            ]);
        });
    }

    public function size(string $queue): int
    {
        return $this->connection->table($this->table)->where('queue', $queue)->count();
    }

    public function failed(int $limit = 50): array
    {
        $rows = $this->connection->table($this->failedTable)->select('id', 'queue', 'job', 'exception', 'message', 'failed_at')->orderBy('id', 'desc')->limit(max(1, min($limit, 1000)))->get();
        $failed = [];

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $message = $row['message'] ?? null;

            if ((\is_int($id) || \is_string($id)) && \is_string($row['queue'] ?? null) && \is_string($row['job'] ?? null) && \is_string($row['exception'] ?? null) && is_numeric($row['failed_at'] ?? null)) {
                $failed[] = new FailedJob($id, $row['queue'], $row['job'], $row['exception'], \is_string($message) ? $message : null, (int) $row['failed_at']);
            }
        }

        return $failed;
    }

    public function retry(int|string|null $id, int $now): int
    {
        return $this->connection->transaction(function () use ($id, $now): int {
            $query = $this->connection->table($this->failedTable)->select('id', 'queue', 'job', 'payload')->orderBy('id')->limit(500);
            $moved = 0;

            if ($id !== null) {
                $query = $query->where('id', $id);
            }

            while (true) {
                $rows = $query->get();
                $insert = [];
                $ids = [];

                foreach ($rows as $row) {
                    if (\is_string($row['queue'] ?? null) && \is_string($row['job'] ?? null) && \is_string($row['payload'] ?? null) && (\is_int($row['id'] ?? null) || \is_string($row['id'] ?? null))) {
                        $insert[] = $this->row($row['queue'], $row['job'], $row['payload'], $now, $now, null);
                        $ids[] = $row['id'];
                    }
                }

                if ($ids === []) {
                    return $moved;
                }

                $this->connection->table($this->table)->insert($insert);
                $this->connection->table($this->failedTable)->whereIn('id', $ids)->delete();
                $moved += \count($ids);

                if ($id !== null) {
                    return $moved;
                }
            }
        });
    }

    public function flushFailed(): int
    {
        return $this->connection->table($this->failedTable)->unrestricted()->delete();
    }

    /**
     * @return array<string, string|int|null>
     */
    private function row(string $queue, string $job, string $payload, int $availableAt, int $now, ?string $origin): array
    {
        return ['queue' => $queue, 'job' => $job, 'payload' => $payload, 'attempts' => 0, 'available_at' => $availableAt, 'reserved_at' => null, 'reserved_by' => null, 'origin' => $origin, 'created_at' => $now];
    }

    private function owned(ReservedJob $job): QueryBuilder
    {
        return $this->connection->table($this->table)->where('id', $job->id)->where('reserved_by', $job->worker);
    }
}
