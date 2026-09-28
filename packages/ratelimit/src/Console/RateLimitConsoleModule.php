<?php

declare(strict_types=1);

namespace Trunk\RateLimit\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The rate-limit console commands, listed in trunk.php only while both the rate-limit and console
 * capabilities are enabled.
 */
final class RateLimitConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(RateLimitTableCommand::class);
        $commands->add(RateLimitPruneCommand::class);
    }
}
