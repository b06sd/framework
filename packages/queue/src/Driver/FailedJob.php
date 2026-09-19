<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

/**
 * A row of the failed-jobs table, as listed by `trunk queue:failed`. The payload is not included.
 */
final readonly class FailedJob
{
    public function __construct(
        public int|string $id,
        public string $queue,
        public string $job,
        public string $exception,
        public ?string $message,
        public int $failedAt,
    ) {}
}
