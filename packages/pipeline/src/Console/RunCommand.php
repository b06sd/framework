<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Pipeline\Exception\PipelineException;
use Trunk\Pipeline\Pipeline;
use Trunk\Pipeline\PipelineRunner;

/**
 * `trunk pipeline:run ImportCustomers` starts a run: one tracking row, one dispatched chunk. The
 * actual work happens as that chunk (and every one after it) runs through `trunk queue:work`.
 */
final readonly class RunCommand implements Command
{
    public function __construct(private PipelineRunner $runner) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('pipeline:run', 'Start a pipeline run', ['Name' => 'the pipeline class name, e.g. ImportCustomers'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $name = (string) $input->argument(0);
        $class = str_contains($name, '\\') ? $name : 'App\\Pipelines\\' . $name;

        if (!class_exists($class) || !is_subclass_of($class, Pipeline::class)) {
            throw new CommandFailedException(\sprintf('%s is not a mapped pipeline. Run `trunk make:pipeline %s` first.', $class, $name));
        }

        try {
            $runId = $this->runner->start($class);
        } catch (PipelineException $e) {
            throw new CommandFailedException($e->getMessage());
        }

        $output->success(\sprintf('Started run #%d for %s.', $runId, $class));
        $output->line('  "trunk queue:work" processes its chunks; "trunk pipeline:status" shows progress.');

        return 0;
    }
}
