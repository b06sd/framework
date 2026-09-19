<?php

declare(strict_types=1);

namespace Trunk\Database\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Database\Migration\Migrator;

final readonly class MigrateCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('migrate', 'Run every pending database migration');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $ran = $this->migrator->migrate();

        if ($ran === []) {
            $output->info('Nothing to migrate.');

            return 0;
        }

        foreach ($ran as $name) {
            $output->line('  migrated  ' . $name);
        }

        $output->success(\count($ran) . ' migration(s) run.');

        return 0;
    }
}
