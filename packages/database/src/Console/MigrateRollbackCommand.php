<?php

declare(strict_types=1);

namespace Trunk\Database\Console;

use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\UsageException;
use Trunk\Database\Migration\Migrator;

final readonly class MigrateRollbackCommand implements Command
{
    public function __construct(private Migrator $migrator) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('migrate:rollback', 'Roll back the last batch of migrations', options: ['--step=N' => 'roll back the last N migrations instead of the last batch']);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $step = $input->option('step', '0');

        if ($step === null || preg_match('/^\d{1,4}$/D', $step) !== 1) {
            throw new UsageException('--step must be a whole number, e.g. --step=2.');
        }

        $rolled = $this->migrator->rollback((int) $step);

        if ($rolled === []) {
            $output->info('Nothing to roll back.');

            return 0;
        }

        foreach ($rolled as $name) {
            $output->line('  rolled back  ' . $name);
        }

        $output->success(\count($rolled) . ' migration(s) rolled back.');

        return 0;
    }
}
