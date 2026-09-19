<?php

declare(strict_types=1);

namespace Trunk\Console\Commands;

use Trunk\Console\Process\ProcessRunner;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Project\Project;

final readonly class ServeCommand implements Command
{
    public const string DEFAULT_PORT = '8006';

    /**
     * @param array<string, string> $variables APP_PORT from the environment or .env sets the default port
     */
    public function __construct(
        private Project $project,
        private ProcessRunner $runner,
        private array $variables = [],
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('serve', 'Start the PHP development server (APP_ENV=local)', options: [
            '--host=<host>' => 'default 127.0.0.1',
            '--port=<port>' => 'default 8006, or APP_PORT from .env',
        ]);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $host = $input->option('host', '127.0.0.1') ?? '127.0.0.1';
        $port = $input->option('port') ?? $this->variables['APP_PORT'] ?? self::DEFAULT_PORT;

        if (!ctype_digit($port)) {
            throw new CommandFailedException('The port must be a number.');
        }

        // Validate before announcing anything.
        $this->runner->serveCommand($this->project, $host, (int) $port);
        $output->info(\sprintf('Serving %s at http://%s:%s (press Ctrl+C to stop)', $this->project->name, $host, $port));

        return $this->runner->serve($this->project, $host, (int) $port);
    }
}
