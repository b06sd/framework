<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The pipeline's console commands, listed in trunk.php only while both the pipeline and console
 * capabilities are enabled.
 */
final class PipelineConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(MakePipelineCommand::class);
        $commands->add(PipelineTableCommand::class);
        $commands->add(RunCommand::class);
        $commands->add(StatusCommand::class);
        $commands->add(ResumeCommand::class);
    }
}
