<?php

declare(strict_types=1);

namespace Trunk\Cache;

use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use Trunk\Cache\Store\Store;
use Trunk\Cache\Store\StoreFactory;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Module;
use Trunk\Support\SystemClock;

/**
 * Registers the PSR-16 cache. Reads `cache.driver`, `cache.path` and `cache.prefix` from config/cache.php
 * (published by `trunk package:install cache`). Inject `Psr\SimpleCache\CacheInterface`.
 *
 * @api
 */
final class CacheModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->bindDefault(Clock::class, SystemClock::class);
        $builder->autowire(StoreFactory::class);
        $builder->factory(Store::class, [StoreFactory::class, 'make'], [new ConfigValue('cache.driver', 'string'), new ConfigValue('cache.path', 'string')]);
        $builder->service(Cache::class, Cache::class, [new Reference(Store::class), new Reference(Clock::class), new ConfigValue('cache.prefix', 'string')]);
        $builder->alias(CacheInterface::class, Cache::class);
    }

    public function boot(ContainerInterface $container): void {}
}
