<?php

declare(strict_types=1);

namespace Trunk\Auth\Console;

use Trunk\Auth\Settings\AuthSettings;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Foundation\Runtime;
use Trunk\Support\FileWriter;

/**
 * `trunk auth:table` writes the migration for the sessions, tokens and login-throttle tables (and,
 * with `--users`, a starter users table). It never overwrites.
 */
final readonly class AuthTableCommand implements Command
{
    public function __construct(private Runtime $runtime, private AuthSettings $settings, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('auth:table', 'Create the migration for the auth tables', options: ['--users' => 'also create a starter users table']);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $directory = $this->runtime->basePath . '/database/migrations';

        if ((glob($directory . '/*_create_trunk_auth_tables.php') ?: []) !== []) {
            throw new CommandFailedException('The auth migration already exists; nothing was written.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('database/migrations could not be created.');
        }

        $file = $directory . '/' . gmdate('Y_m_d_His') . '_create_trunk_auth_tables.php';
        $this->files->write($file, AuthTables::migration($this->settings, $input->flag('users')));
        $output->success('Created ' . basename($file));
        $output->line('  Run "trunk migrate" to create the tables.');

        return 0;
    }
}
