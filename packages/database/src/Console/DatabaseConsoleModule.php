<?php

declare(strict_types=1);

namespace Trunk\Database\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The migration commands. Listed in trunk.php only while both the database and console
 * capabilities are enabled, so the database never pulls the console into an application.
 */
final class DatabaseConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(MigrateCommand::class);
        $commands->add(MigrateRollbackCommand::class);
        $commands->add(MigrateStatusCommand::class);
        $commands->add(MigrateFreshCommand::class);
        $commands->add(MakeMigrationCommand::class);
    }
}
