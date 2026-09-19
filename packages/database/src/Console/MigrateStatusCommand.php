<?php

declare(strict_types=1);

namespace Trunk\Database\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Database\Migration\Migrator;

final readonly class MigrateStatusCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('migrate:status', 'Show which migrations have run');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $rows = [];

        foreach ($this->migrator->status() as $row) {
            $rows[] = [$row['name'], $row['ran'] ? 'Ran' : 'Pending', $row['batch'] === null ? '-' : (string) $row['batch']];
        }

        if ($rows === []) {
            $output->info('No migrations found. Create one with "trunk make:migration create_customers_table".');

            return 0;
        }

        $output->table(['Migration', 'Status', 'Batch'], $rows);

        return 0;
    }
}
