<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Console;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * The `openapi` command, listed in trunk.php only while both openapi and console are enabled.
 */
final class OpenApiConsoleModule implements Module, CommandProvider
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function commands(CommandCollector $commands): void
    {
        $commands->add(OpenApiCommand::class);
    }
}
