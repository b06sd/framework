<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Console\Scaffold\Generator;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Project\Project;

/**
 * One class serves every `make:<kind>` command.
 */
final readonly class MakeCommand implements Command
{
    public function __construct(
        private Project $project,
        private Generator $generator,
        private string $kind,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('make:' . $this->kind, 'Generate a ' . $this->kind, ['Name' => 'the class name in PascalCase, e.g. InvoiceService'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $file = $this->generator->make($this->project, $this->kind, (string) $input->argument(0));
        $output->success('Created ' . $file->path);
        $output->line('  ' . $file->hint);

        return 0;
    }
}
