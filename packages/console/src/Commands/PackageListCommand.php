<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Capability\CapabilityManager;
use Trunk\Foundation\Project\Project;

final readonly class PackageListCommand implements Command
{
    public function __construct(
        private Project $project,
        private CapabilityManager $capabilities = new CapabilityManager(),
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('package:list', 'List capabilities and whether this application uses them');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $catalog = $this->capabilities->catalog($this->project);
        $enabled = $catalog->enabled($this->project->modules);
        $partial = $catalog->partiallyEnabled($this->project->modules);
        $rows = [];

        foreach ($catalog->all() as $capability) {
            $status = match (true) {
                \in_array($capability, $enabled, true) => 'enabled',
                \in_array($capability, $partial, true) => 'PARTIAL (some modules missing)',
                $capability->isBuiltIn() => 'available',
                default => 'installed, not enabled',
            };

            $rows[] = [$capability->id, $status, $capability->package, $capability->description];
        }

        $output->table(['Capability', 'Status', 'Package', 'Description'], $rows);

        foreach ($catalog->problems() as $problem) {
            $output->warning($problem);
        }

        $output->line();
        $output->line('Enable one with `trunk package:install <capability>` or install a Composer package with `trunk package:install vendor/name`.');

        return 0;
    }
}
