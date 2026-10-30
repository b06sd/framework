<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use Psr\Container\ContainerInterface;
use Trunk\Auth\Http\RequireJwt;
use Trunk\Auth\Http\RequireToken;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

final class TokenRoutesModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/me/orders/{id:int}', [DocumentedOrderController::class, 'show'], 'me.orders.show', middleware: [RequireToken::class]);
        $routes->post('/orders/preview', [DocumentedOrderController::class, 'preview']);
        $routes->get('/jwt/orders/{id:int}', [DocumentedOrderController::class, 'show'], 'jwt.orders.show', middleware: [RequireJwt::class]);
    }
}
