<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\TypedInputController;

/** Reads a `#[From(Source::Json)]` class through `#[Query]`, which the build refuses. */
final class MisboundInputModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(TypedInputController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/rename', [TypedInputController::class, 'queryFromJsonClass']);
    }
}
