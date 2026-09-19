<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\PageController;
use Trunk\Tests\Fixtures\Middleware\OrderAMiddleware;
use Trunk\Tests\Fixtures\Middleware\OrderBMiddleware;
use Trunk\Tests\Fixtures\Middleware\StopMiddleware;

final class RouteMiddlewareModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(PageController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/open', [PageController::class, 'list']);
        $routes->group('/api', static function (RouteCollector $api): void {
            $api->get('/plain', [PageController::class, 'list']);
            $api->get('/extra', [PageController::class, 'list'], middleware: [OrderBMiddleware::class]);
            $api->group('/deep', static function (RouteCollector $deep): void {
                $deep->get('/route', [PageController::class, 'list'], middleware: [OrderBMiddleware::class]);
            }, middleware: [StopMiddleware::class]);
        }, middleware: [OrderAMiddleware::class]);
    }
}
