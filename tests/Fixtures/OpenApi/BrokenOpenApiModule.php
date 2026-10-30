<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\OpenApi;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

final class BrokenOpenApiModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/broken', [BrokenReturnsController::class, 'show']);
    }
}
