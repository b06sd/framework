<?php

declare(strict_types=1);

namespace Trunk\Lifecycle;

use InvalidArgumentException;

/**
 * Limits and thresholds for a long-running process. Zero disables a limit.
 */
final readonly class MemoryPolicy
{
    /**
     * @param int $limitBytes      restart when memory use reaches this many bytes
     * @param int $gcInterval      run gc_collect_cycles() every this many units of work (0 = never); never per unit
     * @param int $growthWarnBytes report growth once memory is this far above the baseline
     * @param int $warmup          units to run before the baseline is taken (caches and autoloading settle)
     * @param int $window          how many recent samples are kept to judge the trend
     */
    public function __construct(
        public int $limitBytes = 0,
        public int $gcInterval = 100,
        public int $growthWarnBytes = 64 * 1024 * 1024,
        public int $warmup = 10,
        public int $window = 20,
    ) {
        if ($limitBytes < 0 || $gcInterval < 0 || $growthWarnBytes < 0 || $warmup < 0 || $window < 2 || $window > 1000) {
            throw new InvalidArgumentException('Memory limits must be zero or positive, and the window between 2 and 1000 samples.');
        }
    }

    /**
     * Reads a size such as "512M", "2G" or a plain byte count.
     */
    public static function bytes(string $size): int
    {
        if (preg_match('/^(\d{1,12})([KMG]?)B?$/iD', $size, $m) !== 1) {
            throw new InvalidArgumentException('A memory size looks like 512M, 2G or a number of bytes.');
        }

        return (int) $m[1] * match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            default => 1,
        };
    }
}
