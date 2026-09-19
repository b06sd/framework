<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

use InvalidArgumentException;

final readonly class WorkerOptions
{
    /**
     * @param list<string> $queues       in priority order
     * @param int          $maxJobs      stop after this many jobs (0 = no limit)
     * @param int          $maxTime      stop after this many seconds (0 = no limit)
     * @param int          $memoryMb     stop when memory use reaches this many MB (0 = no limit)
     * @param int          $sleep        seconds to wait when no job is available
     * @param bool         $once         process at most one job, then exit
     * @param bool         $stopWhenEmpty exit as soon as no job is available
     * @param int          $gcInterval   run a garbage-collection cycle every this many jobs (0 = never)
     * @param int          $growthWarnMb log a warning once memory is this far above the baseline
     */
    public function __construct(
        public array $queues = ['default'],
        public int $maxJobs = 0,
        public int $maxTime = 0,
        public int $memoryMb = 0,
        public int $sleep = 3,
        public bool $once = false,
        public bool $stopWhenEmpty = false,
        public int $gcInterval = 100,
        public int $growthWarnMb = 64,
    ) {
        if ($queues === []) {
            throw new InvalidArgumentException('At least one queue is required.');
        }

        foreach ($queues as $queue) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $queue) !== 1) {
                throw new InvalidArgumentException('Queue names use a-z, 0-9, dot, dash and underscore (1 to 64 characters).');
            }
        }

        if ($gcInterval < 0 || $growthWarnMb < 0) {
            throw new InvalidArgumentException('gc-interval and growth-warn must be zero or positive.');
        }

        if ($maxJobs < 0 || $maxTime < 0 || $memoryMb < 0 || $sleep < 0 || $sleep > 3600) {
            throw new InvalidArgumentException('max-jobs, max-time, memory and sleep must be zero or positive (sleep at most 3600).');
        }
    }
}
