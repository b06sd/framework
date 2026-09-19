<?php

declare(strict_types=1);

namespace Trunk\Database\Console;

use DateTimeImmutable;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Database\Migration\MigrationStub;
use Trunk\Database\Migration\Migrator;

final readonly class MakeMigrationCommand implements Command
{
    public function __construct(private Migrator $migrator, private MigrationStub $stub = new MigrationStub()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('make:migration', 'Create a migration file', ['name' => 'snake_case name, e.g. create_customers_table'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $path = $this->stub->create($this->migrator->directory(), (string) $input->argument(0), new DateTimeImmutable());
        $output->success('Created ' . basename($path));
        $output->line('  Edit it, then run "trunk migrate".');

        return 0;
    }
}
