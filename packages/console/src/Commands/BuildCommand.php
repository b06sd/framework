<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Compiler\Build\BuildRunner;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Capability\Capability;
use Trunk\Foundation\Capability\CapabilityCatalog;
use Trunk\Foundation\Capability\ComposerRequirements;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Project\ConfigurationLoader;
use Trunk\Foundation\Project\Project;
use Trunk\Foundation\Runtime;

/**
 * Compiles the application for production: container, configuration, and whatever each capability
 * contributes (routes, views, commands). Everything is validated first; nothing is written if
 * anything is wrong.
 */
final readonly class BuildCommand implements Command
{
    /**
     * @param array<string, string> $variables settings from the environment and .env; values used by config files are compiled into build/config.php
     */
    public function __construct(
        private Project $project,
        private array $variables = [],
        private ConfigurationLoader $configuration = new ConfigurationLoader(),
        private BuildRunner $runner = new BuildRunner(),
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('build', 'Compile the application for production (writes build/)');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $catalog = new CapabilityCatalog($this->project->basePath);
        $missing = $catalog->missingRequirements($this->project->modules);

        if ($missing !== []) {
            throw new CommandFailedException("Cannot build: an enabled capability is missing something it requires.\n" . implode("\n", array_map(static fn(array $m): string => \sprintf('  - %s needs "%s". Fix: trunk package:install %s', $m[0]->name, $m[1], $m[1]), $missing)));
        }

        $requirements = new ComposerRequirements();
        $absent = $requirements->missing($this->project->basePath, $requirements->for($catalog->enabled($this->project->modules)));

        if ($absent !== []) {
            throw new CommandFailedException('Cannot build: an enabled capability needs software that is not installed (' . $requirements->describe($absent) . ').');
        }

        $runtime = new Runtime(Environment::Production, false, $this->project->basePath, $this->variables);
        $configuration = $this->configuration->evaluate($this->project, $runtime);
        $report = $this->runner->run(new ModuleManifest($this->project->modules), $runtime, $configuration, $this->project->buildDirectory());

        $output->success('Built for production into build/.');
        $output->line(\sprintf('  %d module(s) contributed artifacts; %d class(es) were wired automatically.', \count($report->contributors), \count($report->autoRegistered)));

        $compiled = array_map(static fn(Capability $c): string => $c->id, $catalog->enabled($this->project->modules));
        $skipped = array_map(static fn(Capability $c): string => $c->id, array_filter($catalog->all(), static fn(Capability $c): bool => !\in_array($c->id, $compiled, true)));
        $output->line('  Compiled capabilities: ' . ($compiled === [] ? '(none)' : implode(', ', $compiled)) . '.');
        $output->line('  Not compiled (not enabled): ' . ($skipped === [] ? '(none)' : implode(', ', $skipped)) . '.');

        if ($input->verbose()) {
            foreach ($report->autoRegistered as $class) {
                $output->line('  - ' . $class);
            }
        }

        $output->line('Run it with APP_ENV=production (the default when APP_ENV is unset).');
        $output->line('Settings from the environment and .env that your config files read are compiled in; rebuild after changing them.');

        return 0;
    }
}
