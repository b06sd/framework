<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\SignupController;

final class ValidationWebModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(SignupController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->post('/signup', [SignupController::class, 'store']);
        $routes->get('/search', [SignupController::class, 'search']);
    }
}
