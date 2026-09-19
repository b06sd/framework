<?php

declare(strict_types=1);

namespace Trunk\Auth\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The auth commands, listed in trunk.php only while both the auth and console capabilities are
 * enabled.
 */
final class AuthConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(AuthTableCommand::class);
        $commands->add(AuthTokenCommand::class);
        $commands->add(AuthPruneCommand::class);
    }
}
