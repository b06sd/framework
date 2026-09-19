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
use Trunk\Tests\Fixtures\Middleware\HeaderMiddleware;

final class WebModule implements Module, RouteProvider, MiddlewareProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(PageController::class);
        $builder->autowire(HeaderMiddleware::class);
        $builder->autowire(BlockingMiddleware::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/pages/{id:int}', [PageController::class, 'show']);
        $routes->get('/list/{page?}', [PageController::class, 'list']);
        $routes->get('/flag/{on}', [PageController::class, 'flag']);
        $routes->get('/echo/{text}', [PageController::class, 'echo']);
        $routes->get('/bad', [PageController::class, 'bad']);
        $routes->get('/boom', [PageController::class, 'boom']);
    }

    public function middleware(MiddlewareCollector $middleware): void
    {
        $middleware->add(HeaderMiddleware::class);
        $middleware->add(BlockingMiddleware::class);
    }
}
