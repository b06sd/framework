<?php

declare(strict_types=1);

namespace Trunk\Orm\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The ORM's console commands, listed in trunk.php only while both the orm and console
 * capabilities are enabled.
 */
final class OrmConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(MakeEntityCommand::class);
        $commands->add(ValidateCommand::class);
    }
}
