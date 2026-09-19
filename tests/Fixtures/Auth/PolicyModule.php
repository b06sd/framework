<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Container\ContainerInterface;
use Trunk\Auth\Http\CsrfMiddleware;
use Trunk\Auth\Http\RequireLogin;
use Trunk\Auth\Http\SessionMiddleware;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Lifetime;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

final class PolicyModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(PostPolicy::class, Lifetime::Scoped);
        $builder->autowire(ManageUsers::class, Lifetime::Scoped);
        $builder->tag('auth.policy', PostPolicy::class);
        $builder->tag('auth.ability', ManageUsers::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->group('/web', static function (RouteCollector $web): void {
            $web->post('/posts/{owner}/update', [PostController::class, 'update']);
            $web->get('/admin', [PostController::class, 'admin']);
        }, middleware: [SessionMiddleware::class, CsrfMiddleware::class, RequireLogin::class]);
    }
}
