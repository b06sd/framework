<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Runtime;
use Trunk\Support\FileWriter;

/**
 * `trunk pipeline:table` writes the migration for the pipeline-runs table. It never overwrites.
 */
final readonly class PipelineTableCommand implements Command
{
    public function __construct(private Runtime $runtime, private Configuration $configuration, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('pipeline:table', 'Create the migration for the pipeline-runs table');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $directory = $this->runtime->basePath . '/database/migrations';

        if (glob($directory . '/*_create_trunk_pipeline_runs_table.php') !== [] && glob($directory . '/*_create_trunk_pipeline_runs_table.php') !== false) {
            throw new CommandFailedException('The pipeline migration already exists; nothing was written.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('database/migrations could not be created.');
        }

        $file = $directory . '/' . gmdate('Y_m_d_His') . '_create_trunk_pipeline_runs_table.php';
        $this->files->write($file, PipelineTables::migration($this->configuration->string('pipeline.table')));
        $output->success('Created ' . basename($file));
        $output->line('  Run "trunk migrate" to create the table.');

        return 0;
    }
}
