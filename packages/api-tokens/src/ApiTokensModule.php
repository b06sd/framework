<?php

declare(strict_types=1);

namespace Trunk\ApiTokens;

use Psr\Container\ContainerInterface;
use Trunk\Auth\AuthModule;
use Trunk\Auth\Http\CsrfMiddleware;
use Trunk\Auth\Http\RequireLogin;
use Trunk\Auth\Http\SessionMiddleware;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Lifetime;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Mvc\MvcModule;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;

/**
 * A page at /account/tokens where signed-in users create, see and revoke their own API tokens.
 * Settings in config/api_tokens.php; the page is resources/views/account/tokens.tusk.php.
 *
 * @api
 */
final class ApiTokensModule implements Module, RouteProvider, ModuleDependencies
{
    public function requires(): array
    {
        return [AuthModule::class, MvcModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(TokenPageController::class, Lifetime::Scoped, [
            'abilities' => new ConfigValue('api_tokens.abilities', 'array'),
            'maxActive' => new ConfigValue('api_tokens.max_active', 'int'),
        ]);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $web = [SessionMiddleware::class, CsrfMiddleware::class, RequireLogin::class];
        $routes->get(TokenPageController::PATH, [TokenPageController::class, 'index'], 'account.tokens', $web);
        $routes->post(TokenPageController::PATH, [TokenPageController::class, 'store'], 'account.tokens.store', $web);
        $routes->post(TokenPageController::PATH . '/{id:[A-Za-z0-9_-]{16}}/revoke', [TokenPageController::class, 'revoke'], 'account.tokens.revoke', $web);
    }
}
