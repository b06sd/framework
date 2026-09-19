<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\ProbeController;
use Trunk\Tests\Fixtures\Di\RequestContext;
use Trunk\Tests\Fixtures\Di\Sibling;
use Trunk\Tests\Fixtures\Di\Token;

/**
 * Note that the controller is not registered anywhere: it is a route root and is wired automatically.
 */
final class ScopeModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->scoped(RequestContext::class);
        $builder->scoped(Sibling::class);
        $builder->singleton(Token::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/probe', [ProbeController::class, 'show']);
    }
}
