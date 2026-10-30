<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\SignupController;
use Trunk\Tests\Fixtures\Controllers\TypedInputController;

final class ValidationWebModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(SignupController::class);
        $builder->autowire(TypedInputController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->post('/signup', [SignupController::class, 'store']);
        $routes->get('/search', [SignupController::class, 'search']);
        $routes->post('/shops/{shop:int}/orders', [TypedInputController::class, 'store']);
        $routes->get('/typed/search', [TypedInputController::class, 'search']);
        $routes->put('/typed/rename', [TypedInputController::class, 'rename']);
    }
}
