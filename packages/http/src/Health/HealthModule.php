<?php

declare(strict_types=1);

namespace Trunk\Http\Health;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Health\HealthChecker;
use Trunk\Http\HttpModule;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Observability\MetricsExporter;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

/**
 * Adds GET /health/live, GET /health/ready and GET /metrics (the last one only when
 * `health.metrics_token` is set). Enable with `trunk package:install health`.
 *
 * @api
 */
final class HealthModule implements Module, RouteProvider, ModuleDependencies
{
    public function requires(): array
    {
        return [HttpModule::class, DiagnosticsModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(HealthController::class, HealthController::class, [
            new Reference(HealthChecker::class),
            new Reference(ResponseBuilder::class),
            new Reference(MetricsExporter::class),
            new ConfigValue('health.metrics_token', 'string'),
            new ConfigValue('health.debug', 'bool'),
        ]);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/health/live', [HealthController::class, 'live'], 'health.live');
        $routes->get('/health/ready', [HealthController::class, 'ready'], 'health.ready');
        $routes->get('/metrics', [HealthController::class, 'metrics'], 'health.metrics');
    }
}
