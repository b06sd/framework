<?php

declare(strict_types=1);

namespace Trunk\RateLimit\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Configuration;
use Trunk\RateLimit\RateLimiter;

/**
 * `trunk rate-limit:prune` deletes finished counter windows. Not required for correctness (an
 * expired window is never counted again), only to keep the table small. Run it from cron.
 */
final readonly class RateLimitPruneCommand implements Command
{
    public function __construct(private RateLimiter $limiter, private Configuration $configuration) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('rate-limit:prune', 'Delete finished rate-limit counters');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $removed = $this->limiter->prune($this->configuration->int('ratelimit.window'));
        $output->success(\sprintf('Removed %d counter(s).', $removed));

        return 0;
    }
}
