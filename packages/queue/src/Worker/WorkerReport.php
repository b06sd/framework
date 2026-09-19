<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

final readonly class WorkerReport
{
    public function __construct(
        public int $succeeded = 0,
        public int $retried = 0,
        public int $failed = 0,
        public int $lost = 0,
        public string $stoppedBecause = '',
    ) {}

    public function processed(): int
    {
        return $this->succeeded + $this->retried + $this->failed + $this->lost;
    }
}
