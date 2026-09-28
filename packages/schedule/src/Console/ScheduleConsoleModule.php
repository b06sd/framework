<?php

declare(strict_types=1);

namespace Trunk\Schedule\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The scheduler's console commands, listed in trunk.php only while both the schedule and console
 * capabilities are enabled.
 */
final class ScheduleConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(ScheduleRunCommand::class);
        $commands->add(ScheduleListCommand::class);
    }
}
