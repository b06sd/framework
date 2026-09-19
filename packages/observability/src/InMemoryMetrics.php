<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

use InvalidArgumentException;
use Trunk\Observability\Metrics;
use Trunk\Observability\MetricsExporter;

/**
 * Process-local metrics with a hard cap on distinct series (a runaway label cannot exhaust memory;
 * new series beyond the cap are dropped and counted). Meaningful in long-running processes
 * (workers, resident servers); under PHP-FPM each process sees only its own requests, so use a
 * shared-store implementation of Metrics there.
 */
final class InMemoryMetrics implements Metrics, MetricsExporter
{
    public const array BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0];

    private const int MAX_LABELS = 5;

    private const int MAX_VALUE_LENGTH = 64;

    /** @var array<string, MetricSeries> */
    private array $series = [];

    private int $dropped = 0;

    public function __construct(private readonly int $maxSeries = 1000) {}

    public function counter(string $name, array $labels = [], float $by = 1.0): void
    {
        if ($by < 0) {
            throw new InvalidArgumentException('A counter can only increase.');
        }

        $id = $this->seriesId($name, 'counter', $labels);

        if ($id !== null) {
            $this->series[$id]->value += $by;
        }
    }

    public function gauge(string $name, float $value, array $labels = []): void
    {
        $id = $this->seriesId($name, 'gauge', $labels);

        if ($id !== null) {
            $this->series[$id]->value = $value;
        }
    }

    public function observe(string $name, float $value, array $labels = []): void
    {
        $id = $this->seriesId($name, 'histogram', $labels);

        if ($id === null) {
            return;
        }

        $series = $this->series[$id];
        $series->buckets = array_map(static fn(int $count, float $bound): int => $value <= $bound ? $count + 1 : $count, $series->buckets, self::BUCKETS);
        $series->sum += $value;
        ++$series->count;
    }

    public function enabled(): bool
    {
        return true;
    }

    public function contentType(): string
    {
        return 'text/plain; version=0.0.4; charset=utf-8';
    }

    public function export(): string
    {
        return new PrometheusFormatter()->format($this);
    }

    /**
     * @return array<string, MetricSeries>
     */
    public function snapshot(): array
    {
        return $this->series;
    }

    /** How many observations were dropped because the series cap was reached. */
    public function dropped(): int
    {
        return $this->dropped;
    }

    /**
     * @param array<string, string> $labels
     *
     * @return string|null the series id, or null when the cap dropped it
     */
    private function seriesId(string $name, string $type, array $labels): ?string
    {
        if (preg_match('/^[a-z_:][a-z0-9_:]{0,99}$/D', $name) !== 1) {
            throw new InvalidArgumentException('A metric name uses lowercase letters, digits, underscores and colons.');
        }

        if (\count($labels) > self::MAX_LABELS) {
            throw new InvalidArgumentException(\sprintf('At most %d labels per metric.', self::MAX_LABELS));
        }

        ksort($labels);
        $clean = [];

        foreach ($labels as $key => $value) {
            if (preg_match('/^[a-z_][a-z0-9_]{0,49}$/D', $key) !== 1) {
                throw new InvalidArgumentException('A label name uses lowercase letters, digits and underscores.');
            }

            $clean[$key] = substr(preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '', 0, self::MAX_VALUE_LENGTH);
        }

        $id = $name . '|' . json_encode($clean);

        if (!isset($this->series[$id])) {
            if (\count($this->series) >= $this->maxSeries) {
                ++$this->dropped;

                return null;
            }

            $this->series[$id] = new MetricSeries($name, $type, $clean, 0.0, array_fill(0, \count(self::BUCKETS), 0));
        }

        if ($this->series[$id]->type !== $type) {
            throw new InvalidArgumentException(\sprintf('"%s" is already a %s and cannot also be a %s.', $name, $this->series[$id]->type, $type));
        }

        return $id;
    }
}
