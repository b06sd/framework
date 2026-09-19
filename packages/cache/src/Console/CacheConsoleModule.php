<?php

declare(strict_types=1);

namespace Trunk\Cache\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The cache's console commands. It is listed in trunk.php only while both the cache and the
 * console capabilities are enabled (a capability integration), so an application without the
 * console never loads any console class because of the cache.
 */
final class CacheConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(CacheClearCommand::class);
    }
}
