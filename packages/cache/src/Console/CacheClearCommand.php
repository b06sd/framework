<?php

declare(strict_types=1);

namespace Trunk\Cache\Console;

use Psr\SimpleCache\CacheInterface;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;

/**
 * `trunk cache:clear`. Works with whatever PSR-16 cache the application has bound.
 */
final readonly class CacheClearCommand implements Command
{
    public function __construct(private CacheInterface $cache) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('cache:clear', 'Remove everything from the application cache');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        if (!$this->cache->clear()) {
            $output->failure('The cache could not be cleared.');

            return 1;
        }

        $output->success('Application cache cleared.');

        return 0;
    }
}
