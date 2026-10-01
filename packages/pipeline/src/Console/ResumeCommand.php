<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Pipeline\Exception\PipelineException;
use Trunk\Pipeline\PipelineRunner;

/**
 * `trunk pipeline:resume <run-id>` re-dispatches one chunk job at a run's last recorded cursor — for
 * a run stuck `running` with no job in flight (a crash between one chunk succeeding and the next
 * being dispatched). Not needed for an ordinary retry: a chunk that fails is retried by the queue
 * itself, same as any other job.
 */
final readonly class ResumeCommand implements Command
{
    public function __construct(private PipelineRunner $runner) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('pipeline:resume', 'Re-dispatch a stalled run\'s next chunk', ['run-id' => 'the run to resume'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $runId = (int) $input->argument(0);

        try {
            $this->runner->resume($runId);
        } catch (PipelineException $e) {
            throw new CommandFailedException($e->getMessage());
        }

        $output->success(\sprintf('Re-dispatched the next chunk for run #%d.', $runId));

        return 0;
    }
}
