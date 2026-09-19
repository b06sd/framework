<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use Trunk\Contracts\Clock;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\UsageException;
use Trunk\Queue\Driver\QueueDriver;

final readonly class RetryCommand implements Command
{
    public function __construct(private QueueDriver $driver, private Clock $clock) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('queue:retry', 'Put failed jobs back on their queue', ['id' => 'a failed job id, or "all"'], requiredArguments: 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $target = (string) $input->argument(0);

        if ($target !== 'all' && preg_match('/^\d{1,18}$/D', $target) !== 1) {
            throw new UsageException('Give a failed job id (a number) or "all".');
        }

        $moved = $this->driver->retry($target === 'all' ? null : (int) $target, $this->clock->now());
        $output->success($moved . ' job(s) put back on the queue.');

        return 0;
    }
}
