<?php

declare(strict_types=1);

namespace Trunk\Console;

use Trunk\Contracts\Console\Command;

/**
 * The commands an application's modules contribute, and the reasons any of them could not be loaded.
 * One command that cannot be built (a missing service, say) must not hide the others, so it is
 * reported instead of thrown.
 */
final readonly class CommandSet
{
    /**
     * @param array<string, Command> $commands by name
     * @param list<string>           $problems one line each: which command class, and why it was skipped
     */
    public function __construct(public array $commands = [], public array $problems = []) {}
}
