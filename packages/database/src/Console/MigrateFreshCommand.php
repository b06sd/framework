<?php

declare(strict_types=1);

namespace Trunk\Database\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Database\Migration\Migrator;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

/**
 * Drops every table. It refuses in production and there is deliberately no flag to override that.
 */
final readonly class MigrateFreshCommand implements Command
{
    public function __construct(private Migrator $migrator, private Runtime $runtime) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('migrate:fresh', 'Drop every table and run all migrations again (never in production)');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        if ($this->runtime->environment === Environment::Production) {
            throw new CommandFailedException('migrate:fresh drops every table and is disabled in production. Set APP_ENV=local for a development database.');
        }

        $ran = $this->migrator->fresh();
        $output->success('Dropped all tables and ran ' . \count($ran) . ' migration(s).');

        return 0;
    }
}
