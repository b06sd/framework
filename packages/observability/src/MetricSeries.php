<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

use Trunk\Observability\Metrics;

/**
 * One labelled series. Owned by the Metrics implementation; read by exporters.
 */
final class MetricSeries
{
    /**
     * @param array<string, string> $labels
     * @param list<int>             $buckets cumulative counts per InMemoryMetrics::BUCKETS bound (histograms only)
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $labels,
        public float $value = 0.0,
        public array $buckets = [],
        public float $sum = 0.0,
        public int $count = 0,
    ) {}
}
