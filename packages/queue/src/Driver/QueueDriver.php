<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

/**
 * Where jobs wait. Times are Unix seconds passed in by the caller (never read from a global clock),
 * so behaviour is testable and identical across drivers.
 */
interface QueueDriver
{
    /**
     * @param string|null $origin JSON with the requestId and traceId of whoever queued the job (already validated)
     */
    public function push(string $queue, string $job, string $payload, int $availableAt, int $now, ?string $origin = null): int|string;

    /**
     * @param list<array{queue: string, job: string, payload: string, availableAt: int, origin?: string|null}> $jobs
     *
     * @return int the number of jobs stored
     */
    public function pushMany(array $jobs, int $now): int;

    /**
     * Claims the oldest available job, trying `$queues` in the order given (earlier = higher
     * priority). Each claim counts an attempt immediately, so a job that kills its worker still
     * runs out of tries. A claimed job becomes claimable again after `$visibilityTimeout` seconds.
     *
     * @param list<string> $queues
     */
    public function reserve(array $queues, int $now, int $visibilityTimeout, string $worker): ?ReservedJob;

    /**
     * @return bool false when the job is no longer this worker's (it timed out and was claimed elsewhere)
     */
    public function complete(ReservedJob $job): bool;

    /**
     * Puts the job back to run again at `$availableAt`.
     *
     * @return bool false when the job is no longer this worker's
     */
    public function release(ReservedJob $job, int $availableAt): bool;

    /**
     * Moves the job to the failed table. `$message` is null unless the application opted in to storing it.
     */
    public function fail(ReservedJob $job, string $exception, ?string $message, int $now): void;

    public function size(string $queue): int;

    /**
     * @return list<FailedJob> newest first
     */
    public function failed(int $limit = 50): array;

    /**
     * Moves failed jobs back onto their queue with a fresh attempt count. `null` retries all.
     *
     * @return int how many were re-queued
     */
    public function retry(int|string|null $id, int $now): int;

    public function flushFailed(): int;
}
