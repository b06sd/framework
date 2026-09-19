<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Logging\ContextHolder;
use Trunk\Observability\Metrics;
use Trunk\Observability\MetricsExporter;
use Trunk\Observability\Tracer;

/**
 * Replaces the core's no-op Metrics and Tracer with in-process metrics (Prometheus text format) and
 * span records written through the structured logger. Configured by config/observability.php.
 *
 * @api
 */
final class TelemetryModule implements Module, ModuleDependencies
{
    public function requires(): array
    {
        return [LoggingModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(MetricsFactory::class, MetricsFactory::class);
        $builder->factory(Metrics::class, [MetricsFactory::class, 'create'], [new Reference(Configuration::class)]);
        $builder->factory(MetricsExporter::class, [MetricsFactory::class, 'exporter'], [new Reference(Metrics::class)]);
        $builder->service(TracerFactory::class, TracerFactory::class);
        $builder->factory(Tracer::class, [TracerFactory::class, 'create'], [new Reference(Configuration::class), new Reference(LoggerInterface::class), new Reference(ContextHolder::class)]);
    }

    public function boot(ContainerInterface $container): void {}
}
