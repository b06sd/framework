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
 * `trunk auth:table` writes the migration for the sessions, tokens, login-throttle and one-time-link
 * tables (and, with `--users`, a starter users table). `--links` writes only the one-time-link table
 * (and the users table's verified column), for a project created before they existed. It never
 * overwrites.
 */
final readonly class AuthTableCommand implements Command
{
    public function __construct(private Runtime $runtime, private AuthSettings $settings, private FileWriter $files = new FileWriter()) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('auth:table', 'Create the migration for the auth tables', options: ['--users' => 'also create a starter users table', '--links' => 'only the one-time-link table (password reset, email verification), for an existing project']);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $directory = $this->runtime->basePath . '/database/migrations';
        $links = $input->flag('links');
        $suffix = $links ? '_create_trunk_auth_links.php' : '_create_trunk_auth_tables.php';

        if ((glob($directory . '/*' . $suffix) ?: []) !== []) {
            throw new CommandFailedException('That auth migration already exists; nothing was written.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CommandFailedException('database/migrations could not be created.');
        }

        $file = $directory . '/' . gmdate('Y_m_d_His') . $suffix;
        $this->files->write($file, $links ? AuthTables::linksMigration($this->settings) : AuthTables::migration($this->settings, $input->flag('users')));
        $output->success('Created ' . basename($file));
        $output->line('  Run "trunk migrate" to create the tables.');

        return 0;
    }
}
