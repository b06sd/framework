<?php

declare(strict_types=1);

namespace Trunk\Lifecycle;

use Closure;

/**
 * Watches a long-running worker's memory. After each unit of work it may run a garbage-collection
 * cycle (only every `gcInterval` units, since a collection per unit costs more than it saves),
 * then compares current use with the baseline taken after warm-up and with the recent trend.
 *
 * The verdict is advice, not a kill switch: Growing means "look at this"; Restart means "stop
 * cleanly, the supervisor will start a fresh process". It reads only numbers, never application data.
 */
final class MemoryMonitor
{
    private int $units = 0;

    private ?int $baseline = null;

    /** @var list<int> */
    private array $samples = [];

    private bool $growthReported = false;

    private int $lastCollected = 0;

    /**
     * @param (Closure(): int)|null $usage bytes in use now (default: real allocated memory)
     * @param (Closure(): int)|null $collect runs a GC cycle and returns how many it freed
     */
    public function __construct(
        private readonly MemoryPolicy $policy = new MemoryPolicy(),
        private readonly ?Closure $usage = null,
        private readonly ?Closure $collect = null,
    ) {}

    public function afterUnit(): MemoryVerdict
    {
        ++$this->units;

        if ($this->policy->gcInterval > 0 && $this->units % $this->policy->gcInterval === 0) {
            $this->lastCollected = $this->collect !== null ? ($this->collect)() : gc_collect_cycles();
        }

        $current = $this->current();

        if ($this->policy->limitBytes > 0 && $current >= $this->policy->limitBytes) {
            return MemoryVerdict::Restart;
        }

        if ($this->units < $this->policy->warmup) {
            return MemoryVerdict::Ok;
        }

        $this->baseline ??= $current;
        $this->samples[] = $current;

        if (\count($this->samples) > $this->policy->window) {
            array_shift($this->samples);
        }

        return $this->growth($current);
    }

    /**
     * Numbers for a diagnostic log line: cheap, no application data.
     *
     * @return array{units: int, usage_mb: float, baseline_mb: float|null, peak_mb: float, gc_collected: int}
     */
    public function stats(): array
    {
        return ['units' => $this->units, 'usage_mb' => round($this->current() / 1048576, 1), 'baseline_mb' => $this->baseline === null ? null : round($this->baseline / 1048576, 1), 'peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'gc_collected' => $this->lastCollected];
    }

    public function units(): int
    {
        return $this->units;
    }

    private function current(): int
    {
        return $this->usage !== null ? ($this->usage)() : memory_get_usage(true);
    }

    private function growth(int $current): MemoryVerdict
    {
        $baseline = $this->baseline ?? $current;
        $above = $current - $baseline;

        if ($this->growthReported && $above < intdiv($this->policy->growthWarnBytes, 2)) {
            $this->growthReported = false;
        }

        $climbing = \count($this->samples) >= 2 && $this->samples[\count($this->samples) - 1] > $this->samples[0];

        if (!$this->growthReported && $this->policy->growthWarnBytes > 0 && $above >= $this->policy->growthWarnBytes && $climbing) {
            $this->growthReported = true;

            return MemoryVerdict::Growing;
        }

        return MemoryVerdict::Ok;
    }
}
