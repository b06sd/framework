<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Capability\CapabilityManager;
use Trunk\Foundation\Project\Project;

final readonly class PackageSyncCommand implements Command
{
    public function __construct(
        private Project $project,
        private CapabilityManager $capabilities = new CapabilityManager(),
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('package:sync', 'Add or remove capability integration modules (for example cache:clear when cache and console are both enabled)');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        foreach ($this->capabilities->sync($this->project) as $note) {
            $output->success($note);
        }

        return 0;
    }
}
