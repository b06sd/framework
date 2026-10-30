<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

final class OpenApiRoutesModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(DocumentedOrderController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/orders', [DocumentedOrderController::class, 'index'], 'orders.index');
        $routes->post('/shops/{shop:int}/orders', [DocumentedOrderController::class, 'store']);
        $routes->get('/orders/{id:int}', [DocumentedOrderController::class, 'show'], middleware: [RequireApiKey::class]);
        $routes->delete('/orders/{id:int}', [DocumentedOrderController::class, 'remove']);
        $routes->post('/register', [DocumentedOrderController::class, 'register']);
        $routes->get('/pages/{page?}', [DocumentedOrderController::class, 'page']);
    }
}
