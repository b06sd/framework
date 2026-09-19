<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Closure;
use Throwable;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;

/**
 * Lets commands that need a project appear in `trunk list` and `trunk help` everywhere, while
 * running them outside a project (or in a project with a broken trunk.php) explains what to do.
 * `definition()` never touches the project, so a placeholder is enough for listing.
 */
final readonly class ProjectBoundCommand implements Command
{
    /**
     * @param Closure(Project): Command $make
     */
    public function __construct(
        private Closure $make,
        private ?Project $project,
        private ?Throwable $problem,
    ) {}

    public function definition(): CommandDefinition
    {
        return ($this->make)($this->project ?? new Project('', '', '', []))->definition();
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        if ($this->project === null) {
            throw $this->problem ?? new CommandFailedException('This command must be run inside a Trunk project (no trunk.php found). Create one with `trunk new <name>`.');
        }

        return ($this->make)($this->project)->handle($input, $output);
    }
}
