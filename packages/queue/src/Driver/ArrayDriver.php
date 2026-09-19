<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

/**
 * An in-memory queue with the same rules as the database driver, for application tests: bind it
 * in place of the real driver, dispatch, and inspect `pushed()` (no facade, no global fake).
 */
final class ArrayDriver implements QueueDriver
{
    private int $sequence = 0;

    /** @var array<int, array{queue: string, job: string, payload: string, attempts: int, availableAt: int, reservedAt: int|null, reservedBy: string|null, origin: string|null}> */
    private array $jobs = [];

    /** @var array<int, array{queue: string, job: string, payload: string, exception: string, message: string|null, failedAt: int}> */
    private array $failed = [];

    public function push(string $queue, string $job, string $payload, int $availableAt, int $now, ?string $origin = null): int
    {
        $this->jobs[++$this->sequence] = ['queue' => $queue, 'job' => $job, 'payload' => $payload, 'attempts' => 0, 'availableAt' => $availableAt, 'reservedAt' => null, 'reservedBy' => null, 'origin' => $origin];

        return $this->sequence;
    }

    public function pushMany(array $jobs, int $now): int
    {
        foreach ($jobs as $job) {
            $this->push($job['queue'], $job['job'], $job['payload'], $job['availableAt'], $now, $job['origin'] ?? null);
        }

        return \count($jobs);
    }

    public function reserve(array $queues, int $now, int $visibilityTimeout, string $worker): ?ReservedJob
    {
        foreach ($queues as $queue) {
            foreach ($this->jobs as $id => $job) {
                if ($job['queue'] === $queue && $job['availableAt'] <= $now && ($job['reservedAt'] === null || $job['reservedAt'] <= $now - $visibilityTimeout)) {
                    $origin = $job['origin'];
                    $job['attempts'] = $job['attempts'] + 1;
                    $job['reservedAt'] = $now;
                    $job['reservedBy'] = $worker;
                    $this->jobs[$id] = $job;

                    return new ReservedJob($id, $queue, $job['job'], $job['payload'], $job['attempts'], $worker, $origin);
                }
            }
        }

        return null;
    }

    public function complete(ReservedJob $job): bool
    {
        if (!$this->owns($job)) {
            return false;
        }

        unset($this->jobs[(int) $job->id]);

        return true;
    }

    public function release(ReservedJob $job, int $availableAt): bool
    {
        if (!$this->owns($job)) {
            return false;
        }

        $id = (int) $job->id;
        $row = $this->jobs[$id] ?? null;

        if ($row === null) {
            return false;
        }

        $row['reservedAt'] = null;
        $row['reservedBy'] = null;
        $row['availableAt'] = $availableAt;
        $this->jobs[$id] = $row;

        return true;
    }

    public function fail(ReservedJob $job, string $exception, ?string $message, int $now): void
    {
        if (!$this->owns($job)) {
            return;
        }

        unset($this->jobs[(int) $job->id]);
        $this->failed[++$this->sequence] = ['queue' => $job->queue, 'job' => $job->job, 'payload' => $job->payload, 'exception' => $exception, 'message' => $message, 'failedAt' => $now];
    }

    public function size(string $queue): int
    {
        return \count(array_filter($this->jobs, static fn(array $j): bool => $j['queue'] === $queue));
    }

    public function failed(int $limit = 50): array
    {
        $result = [];

        foreach (array_reverse($this->failed, true) as $id => $job) {
            $result[] = new FailedJob($id, $job['queue'], $job['job'], $job['exception'], $job['message'], $job['failedAt']);
        }

        return \array_slice($result, 0, $limit);
    }

    public function retry(int|string|null $id, int $now): int
    {
        $moved = 0;

        foreach ($this->failed as $failedId => $job) {
            if ($id === null || (string) $failedId === (string) $id) {
                unset($this->failed[$failedId]);
                $this->push($job['queue'], $job['job'], $job['payload'], $now, $now);
                ++$moved;
            }
        }

        return $moved;
    }

    public function flushFailed(): int
    {
        $count = \count($this->failed);
        $this->failed = [];

        return $count;
    }

    /**
     * What is waiting, for assertions in tests.
     *
     * @return list<array{queue: string, job: string, payload: string}>
     */
    public function pushed(): array
    {
        return array_values(array_map(static fn(array $j): array => ['queue' => $j['queue'], 'job' => $j['job'], 'payload' => $j['payload']], $this->jobs));
    }

    private function owns(ReservedJob $job): bool
    {
        return isset($this->jobs[(int) $job->id]) && $this->jobs[(int) $job->id]['reservedBy'] === $job->worker;
    }
}
