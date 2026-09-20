<?php

declare(strict_types=1);

namespace Trunk\Console;

use Throwable;
use Trunk\Container\Scopable;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandCollector;
use Trunk\Contracts\Console\CommandProvider;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\ApplicationFactory;
use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Runtime;

/**
 * Boots the application (compiled in production, development otherwise) and resolves the commands
 * its modules contribute from the container, so they get their dependencies injected.
 */
final readonly class ApplicationCommands
{
    public function __construct(
        private Project $project,
        private Runtime $runtime,
        private ApplicationFactory $factory = new ApplicationFactory(),
    ) {}

    public function load(): CommandSet
    {
        $collector = new CommandCollector();

        foreach ($this->project->modules as $class) {
            $module = new $class();

            if ($module instanceof CommandProvider) {
                $module->commands($collector);
            }
        }

        if ($collector->classes() === []) {
            return new CommandSet();
        }

        $container = $this->factory->create($this->project, $this->runtime)->container();

        if (!$container instanceof Scopable) {
            throw new CommandFailedException('The application container does not support scopes.');
        }

        $commands = [];
        $problems = [];

        foreach ($collector->classes() as $class) {
            try {
                $command = $container->beginScope()->get($class);

                if (!$command instanceof Command) {
                    throw new CommandFailedException(\sprintf('"%s" did not resolve to a console command.', $class));
                }

                $name = $command->definition()->name;

                if (isset($commands[$name])) {
                    throw new CommandFailedException(\sprintf('Two commands are named "%s".', $name));
                }

                $commands[$name] = $command;
            } catch (Throwable $e) {
                $problems[] = \sprintf('%s could not be loaded: %s', $class, trim(strtok($e->getMessage(), "\n") ?: $e::class));
            }
        }

        return new CommandSet($commands, $problems);
    }
}
