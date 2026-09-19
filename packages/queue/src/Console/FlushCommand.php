<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Queue\Driver\QueueDriver;

final readonly class FlushCommand implements Command
{
    public function __construct(private QueueDriver $driver) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('queue:flush', 'Delete every failed job');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $output->success($this->driver->flushFailed() . ' failed job(s) deleted.');

        return 0;
    }
}
