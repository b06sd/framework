<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Container\ContainerInterface;
use Trunk\Auth\Http\RequireJwt;
use Trunk\Auth\Http\RequireToken;
use Trunk\Auth\Jwt\KeySource;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Contracts\Module;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

/** Routes guarded by RequireJwt, with the provider's key set read from `jwt_test.jwks` (a file). */
final class JwtTestModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(KeySource::class, FileKeySource::class, [new ConfigValue('jwt_test.jwks', 'string')]);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->get('/jwt/whoami', [JwtController::class, 'whoami'], middleware: [RequireJwt::class]);
        $routes->get('/token/whoami', [JwtController::class, 'whoami'], middleware: [RequireToken::class]);
    }
}
