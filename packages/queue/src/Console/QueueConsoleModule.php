<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The queue's console commands, listed in trunk.php only while both the queue and console
 * capabilities are enabled.
 */
final class QueueConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(WorkCommand::class);
        $commands->add(TableCommand::class);
        $commands->add(FailedCommand::class);
        $commands->add(RetryCommand::class);
        $commands->add(FlushCommand::class);
        $commands->add(MakeJobCommand::class);
    }
}
