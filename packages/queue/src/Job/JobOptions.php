<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use InvalidArgumentException;

/**
 * Per-job settings. Return it from an optional `public static function options(): JobOptions`.
 *
 * @api
 */
final readonly class JobOptions
{
    /**
     * @param list<int> $backoff seconds to wait before retry 1, 2, 3... (the last value repeats)
     */
    public function __construct(
        public int $tries = 3,
        public array $backoff = [10, 60, 300],
        public int $timeout = 60,
        public string $queue = 'default',
    ) {
        if ($tries < 1 || $tries > 100) {
            throw new InvalidArgumentException('tries must be between 1 and 100.');
        }

        if ($timeout < 1 || $timeout > 3600) {
            throw new InvalidArgumentException('timeout must be between 1 and 3600 seconds.');
        }

        foreach ($backoff as $seconds) {
            if ($seconds < 0 || $seconds > 86400) {
                throw new InvalidArgumentException('every backoff must be between 0 and 86400 seconds.');
            }
        }

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $queue) !== 1) {
            throw new InvalidArgumentException('the queue name must be 1 to 64 characters of a-z, 0-9, dot, dash or underscore.');
        }
    }

    /**
     * Seconds to wait before running the job again after its `$attempt`-th failed attempt (1-based).
     */
    public function delayAfter(int $attempt): int
    {
        if ($this->backoff === []) {
            return 0;
        }

        return $this->backoff[min($attempt, \count($this->backoff)) - 1] ?? 0;
    }
}
