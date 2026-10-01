<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\LeakyTransactionController;

final class LeakyTransactionModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(LeakyTransactionController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->post('/leaky/created', [LeakyTransactionController::class, 'created']);
        $routes->post('/leaky/refused', [LeakyTransactionController::class, 'refused']);
        $routes->post('/leaky/committed', [LeakyTransactionController::class, 'committed']);
        $routes->get('/leaky/notes', [LeakyTransactionController::class, 'count']);
    }
}
