<?php

declare(strict_types=1);

namespace Trunk\Mail\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * Mail's console commands, listed in trunk.php only while both mail and console are enabled.
 */
final class MailConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(MailTestCommand::class);
    }
}
