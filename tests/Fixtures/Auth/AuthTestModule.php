<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Container\ContainerInterface;
use Trunk\Auth\Http\CsrfMiddleware;
use Trunk\Auth\Http\RequireLogin;
use Trunk\Auth\Http\RequireToken;
use Trunk\Auth\Http\SessionMiddleware;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

final class AuthTestModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        // Controllers are scoped roots and are wired automatically, so they may use request-scoped services.
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/plain', [AuthTestController::class, 'plain']);
        $routes->group('/web', static function (RouteCollector $web): void {
            $web->get('/csrf', [AuthTestController::class, 'csrf']);
            $web->get('/form', [AuthTestController::class, 'form']);
            $web->post('/login-form', [AuthTestController::class, 'loginForm']);
            $web->post('/logout-form', [AuthTestController::class, 'logoutForm']);
            $web->get('/secret', [AuthTestController::class, 'secret'], middleware: [RequireLogin::class]);
            $web->post('/login', [AuthTestController::class, 'login']);
            $web->post('/logout', [AuthTestController::class, 'logout']);
            $web->get('/me', [AuthTestController::class, 'me'], middleware: [RequireLogin::class]);
            $web->get('/page', [AuthTestController::class, 'page'], middleware: [RequireLogin::class]);
            $web->post('/put/{key}/{value}', [AuthTestController::class, 'put']);
            $web->get('/get/{key}', [AuthTestController::class, 'get']);
            $web->get('/flash/set', [AuthTestController::class, 'flashSet']);
            $web->get('/flash/get', [AuthTestController::class, 'flashGet']);
        }, middleware: [SessionMiddleware::class, CsrfMiddleware::class]);
        $routes->group('/api', static function (RouteCollector $api): void {
            $api->get('/whoami', [AuthTestController::class, 'whoami']);
        }, middleware: [RequireToken::class]);
    }
}
