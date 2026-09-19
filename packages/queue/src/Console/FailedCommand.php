<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Queue\Driver\QueueDriver;

final readonly class FailedCommand implements Command
{
    public function __construct(private QueueDriver $driver) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('queue:failed', 'List failed jobs');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $failed = $this->driver->failed(100);

        if ($failed === []) {
            $output->info('No failed jobs.');

            return 0;
        }

        $output->table(['ID', 'Queue', 'Job', 'Exception', 'Failed at (UTC)'], array_map(static fn($f): array => [(string) $f->id, $f->queue, $f->job, $f->exception, gmdate('Y-m-d H:i:s', $f->failedAt)], $failed));

        return 0;
    }
}
