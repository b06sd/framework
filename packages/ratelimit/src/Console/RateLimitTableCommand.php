<?php

declare(strict_types=1);

namespace Trunk\RateLimit\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Runtime;
use Trunk\Support\FileWriter;

/**
 * `trunk rate-limit:table` writes the migration for the rate-limit counters. It never overwrites.
 */
final readonly class RateLimitTableCommand implements Command
{
    public function __construct(private Runtime $runtime, private Configuration $configuration, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('rate-limit:table', 'Create the migration for the rate-limit table');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $directory = $this->runtime->basePath . '/database/migrations';

        if ((glob($directory . '/*_create_trunk_rate_limits_table.php') ?: []) !== []) {
            throw new CommandFailedException('The rate-limit migration already exists; nothing was written.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('database/migrations could not be created.');
        }

        $file = $directory . '/' . gmdate('Y_m_d_His') . '_create_trunk_rate_limits_table.php';
        $this->files->write($file, RateLimitTables::migration($this->configuration->string('ratelimit.table')));
        $output->success('Created ' . basename($file));
        $output->line('  Run "trunk migrate" to create the table.');

        return 0;
    }
}
