<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;

final class EchoCommand implements Command
{
    public function definition(): CommandDefinition
    {
        return new CommandDefinition('demo:echo', 'Echo the given text', ['text' => 'what to print'], ['--loud' => 'shout'], 1);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $text = (string) $input->argument(0);
        $output->line($input->flag('loud') ? strtoupper($text) : $text);

        return 0;
    }
}
