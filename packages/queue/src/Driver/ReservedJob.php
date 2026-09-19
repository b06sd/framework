<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

/**
 * A job claimed by a worker. `attempts` already counts this attempt.
 */
final readonly class ReservedJob
{
    public function __construct(
        public int|string $id,
        public string $queue,
        public string $job,
        public string $payload,
        public int $attempts,
        public string $worker,
        public ?string $origin = null,
    ) {}
}
