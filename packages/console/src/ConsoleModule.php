<?php

declare(strict_types=1);

namespace Trunk\Console;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Module;

/**
 * Enables application console commands: the commands other modules contribute become roots of the
 * compiled container, so production builds wire them like controllers.
 *
 * @api
 */
final class ConsoleModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        $collector = new CommandCollector();

        foreach ($context->manifest->modules as $class) {
            $module = new $class();

            if ($module instanceof CommandProvider) {
                $module->commands($collector);
            }
        }

        return new BuildContribution($collector->classes());
    }
}
