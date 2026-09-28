<?php

declare(strict_types=1);

namespace Trunk\RateLimit;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Database\Connection\Connection;
use Trunk\Database\DatabaseModule;
use Trunk\Http\HttpModule;
use Trunk\RateLimit\Middleware\RateLimitMiddleware;
use Trunk\Support\SystemClock;

/**
 * Registers `RateLimiter` (inject it directly for a custom limit) and `RateLimitMiddleware` (the
 * ready-made one, configured from config/ratelimit.php). `trunk rate-limit:table` creates its table.
 *
 * @api
 */
final class RateLimitModule implements Module, ModuleDependencies
{
    public function requires(): array
    {
        return [HttpModule::class, DatabaseModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->bindDefault(Clock::class, SystemClock::class);
        $builder->service(RateLimiter::class, RateLimiter::class, [new Reference(Connection::class), new ConfigValue('ratelimit.table', 'string'), new Reference(Clock::class)]);
        $builder->service(RateLimitMiddleware::class, RateLimitMiddleware::class, [new Reference(RateLimiter::class), new ConfigValue('ratelimit.max', 'int'), new ConfigValue('ratelimit.window', 'int')]);
    }

    public function boot(ContainerInterface $container): void {}
}
