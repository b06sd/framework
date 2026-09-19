<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\UserPageController;

final class MvcWebModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(UserPageController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/users', [UserPageController::class, 'index']);
        $routes->get('/api/users/{id:int}', [UserPageController::class, 'api']);
        $routes->get('/go', [UserPageController::class, 'go']);
        $routes->get('/broken', [UserPageController::class, 'broken']);
        $routes->get('/search/{q}', [UserPageController::class, 'search']);
        $routes->get('/open/{target}', [UserPageController::class, 'open']);
    }
}
