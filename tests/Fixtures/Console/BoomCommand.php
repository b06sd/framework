<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Console;

use RuntimeException;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;

final class BoomCommand implements Command
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('demo:boom', 'Always fails');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        throw new RuntimeException('it broke');
    }
}
