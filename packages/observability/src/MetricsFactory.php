<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

use Trunk\Foundation\Configuration;
use Trunk\Observability\Metrics;
use Trunk\Observability\MetricsExporter;
use Trunk\Observability\NullMetrics;
use Trunk\Observability\NullMetricsExporter;

/**
 * `observability.metrics = true` keeps in-process metrics (exposed by the health capability's
 * /metrics when a token is configured); anything else records nothing.
 */
final readonly class MetricsFactory
{
    public function create(Configuration $configuration): Metrics
    {
        return $configuration->has('observability.metrics') && $configuration->get('observability.metrics') === true ? new InMemoryMetrics() : new NullMetrics();
    }

    public function exporter(Metrics $metrics): MetricsExporter
    {
        return $metrics instanceof MetricsExporter ? $metrics : new NullMetricsExporter();
    }
}
