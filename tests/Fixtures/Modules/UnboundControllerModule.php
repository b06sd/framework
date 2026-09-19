<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Http\Pipeline\MiddlewareCollector;
use Trunk\Http\Pipeline\MiddlewareProvider;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\PageController;
use Trunk\Tests\Fixtures\Middleware\BlockingMiddleware;

/**
 * Routes to a controller and uses a middleware whose dependency (a ResponseFactoryInterface) is never bound.
 */
final class UnboundControllerModule implements Module, RouteProvider, MiddlewareProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/users', [PageController::class, 'list']);
    }

    public function middleware(MiddlewareCollector $middleware): void
    {
        $middleware->add(BlockingMiddleware::class);
    }
}
