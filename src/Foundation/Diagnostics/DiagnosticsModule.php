<?php

declare(strict_types=1);

namespace Trunk\Foundation\Diagnostics;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Error\ExceptionHandler;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Health\HealthChecker;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Observability\Metrics;
use Trunk\Observability\MetricsExporter;
use Trunk\Observability\NullMetrics;
use Trunk\Observability\NullMetricsExporter;
use Trunk\Observability\NullTracer;
use Trunk\Observability\Tracer;

/**
 * The runtime services every long-lived or user-facing process needs on top of a logger: the error
 * pipeline (`ExceptionHandler`, tag `trunk.error_reporter`), the per-request/per-job reset
 * (`LifecycleManager`, tag `trunk.lifecycle`), the health checker (tag `trunk.health_check`) and
 * no-op `Metrics`, `Tracer` and `MetricsExporter` defaults that the observability package replaces.
 * It needs the logging module and is configured by config/errors.php; `trunk build` validates that.
 *
 * @api
 */
final class DiagnosticsModule implements Module, BuildContributor, ModuleDependencies
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
        $builder->service(ExceptionHandler::class, ExceptionHandler::class, [new Reference(LoggerInterface::class), new TaggedReference('trunk.error_reporter')]);
        $builder->service(HealthChecker::class, HealthChecker::class, [new TaggedReference('trunk.health_check'), new Reference(LoggerInterface::class)]);
        $builder->service(LifecycleManager::class, LifecycleManager::class, [new TaggedReference('trunk.lifecycle'), new Reference(LoggerInterface::class)]);
        $builder->bindDefault(Tracer::class, NullTracer::class);
        $builder->bindDefault(Metrics::class, NullMetrics::class);
        $builder->bindDefault(MetricsExporter::class, NullMetricsExporter::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        if ($context->configuration->has('errors.format') && !\in_array($context->configuration->get('errors.format'), ['auto', 'json', 'html', 'text'], true)) {
            throw new CompilationException(['errors.format must be auto, json, html or text (config/errors.php).']);
        }

        return new BuildContribution();
    }
}
