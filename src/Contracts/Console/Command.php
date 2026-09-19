<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console;

/**
 * A console command. Application commands are resolved from the container, so they take their
 * dependencies in the constructor like any other service.
 *
 * @api
 */
interface Command
{
    public function definition(): CommandDefinition;

    /**
     * @return int exit code (0 = success)
     */
    public function handle(CommandInput $input, CommandOutput $output): int;
}
