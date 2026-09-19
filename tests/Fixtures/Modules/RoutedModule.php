<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Tests\Fixtures\Controllers\HomeController;
use Trunk\Tests\Fixtures\Controllers\UserController;

final class RoutedModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/', HomeController::class, 'home');
        $routes->group('/users', static function (RouteCollector $users): void {
            $users->get('/', [UserController::class, 'index'], 'users.index');
            $users->get('/me', [UserController::class, 'show'], 'users.me');
            $users->get('/{id:int}', [UserController::class, 'show'], 'users.show');
            $users->post('/{id:int}', [UserController::class, 'show']);
        });
        $routes->get('/blog/{page?}', [UserController::class, 'index'], 'blog');
        $routes->get('/files/{path:any}', [UserController::class, 'show'], 'files');
        $routes->get("/it's/\$special", [UserController::class, 'show']);
    }
}
