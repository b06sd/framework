<?php

declare(strict_types=1);

namespace Trunk\Contracts\Console;

/**
 * Implemented by modules that contribute console commands.
 *
 * @api
 */
interface CommandProvider
{
    public function commands(CommandCollector $commands): void;
}
