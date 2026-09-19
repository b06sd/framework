<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Console\Process\ProcessRunner;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Project\Project;

final readonly class TestCommand implements Command
{
    public function __construct(
        private Project $project,
        private ProcessRunner $runner,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('test', 'Run the project tests with PHPUnit (extra arguments go to PHPUnit after --)');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        return $this->runner->test($this->project, $input->arguments());
    }
}
