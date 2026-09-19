<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Runtime;
use Trunk\Queue\Driver\QueueTables;
use Trunk\Support\FileWriter;

/**
 * `trunk queue:table` writes the migration for the database driver. It never overwrites.
 */
final readonly class TableCommand implements Command
{
    public function __construct(private Runtime $runtime, private Configuration $configuration, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('queue:table', 'Create the migration for the jobs and failed-jobs tables');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $directory = $this->runtime->basePath . '/database/migrations';

        if (glob($directory . '/*_create_trunk_queue_tables.php') !== [] && glob($directory . '/*_create_trunk_queue_tables.php') !== false) {
            throw new CommandFailedException('The queue migration already exists; nothing was written.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('database/migrations could not be created.');
        }

        $file = $directory . '/' . gmdate('Y_m_d_His') . '_create_trunk_queue_tables.php';
        $this->files->write($file, QueueTables::migration($this->configuration->string('queue.table'), $this->configuration->string('queue.failed_table')));
        $output->success('Created ' . basename($file));
        $output->line('  Run "trunk migrate" to create the tables.');

        return 0;
    }
}
