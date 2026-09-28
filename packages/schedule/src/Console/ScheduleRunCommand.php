<?php

declare(strict_types=1);

namespace Trunk\Schedule\Console;

use DateTimeImmutable;
use Trunk\Console\Process\ProcessLauncher;
use Trunk\Console\Process\ProcOpenLauncher;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Runtime;
use Trunk\Queue\Queue;
use Trunk\Schedule\ScheduledTask;

/**
 * `trunk schedule:run` is the one line a deploy needs in its crontab
 * (`* * * * * cd /app && php vendor/bin/trunk schedule:run`): it runs every task that is due this
 * minute, then exits. A job is dispatched through the queue (in process, cheap); a command runs in
 * its own process, the same binary and working directory `vendor/bin/trunk` itself would use.
 *
 * A flock() guard skips the pass instead of overlapping if the previous one is still running,
 * which only matters if a command takes longer than a minute — jobs return immediately either way.
 */
final readonly class ScheduleRunCommand implements Command
{
    public function __construct(
        private Runtime $runtime,
        private Queue $queue,
        private Clock $clock,
        private ProcessLauncher $launcher = new ProcOpenLauncher(),
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('schedule:run', 'Run every scheduled task that is due this minute');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $tasks = ScheduleFile::load($this->runtime->basePath . '/app/Schedule.php', $output);

        if ($tasks === null) {
            return 0;
        }

        $lock = fopen($this->runtime->basePath . '/storage/schedule.lock', 'c');

        if (!\is_resource($lock)) {
            $output->failure('storage/schedule.lock could not be opened.');

            return 1;
        }

        if (!flock($lock, \LOCK_EX | \LOCK_NB)) {
            $output->info('Another schedule:run is still running; this pass was skipped.');
            fclose($lock);

            return 0;
        }

        try {
            return $this->runDueTasks($tasks, $output);
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param list<ScheduledTask> $tasks
     */
    private function runDueTasks(array $tasks, CommandOutput $output): int
    {
        $now = new DateTimeImmutable('@' . $this->clock->now());
        $ran = 0;

        foreach ($tasks as $task) {
            if (!$task->isDue($now)) {
                continue;
            }

            if ($task->job !== null) {
                $this->queue->dispatch($task->job);
            } elseif ($task->command !== null) {
                $this->runCommand($task->command, $output);
            }

            $ran++;
        }

        $output->success($ran . ' task(s) ran.');

        return 0;
    }

    private function runCommand(string $name, CommandOutput $output): void
    {
        $binary = $this->runtime->basePath . '/vendor/bin/trunk';

        if (!is_file($binary)) {
            $output->warning(\sprintf('Scheduled command "%s" was skipped: %s does not exist. Run `composer install`.', $name, $binary));

            return;
        }

        $exit = $this->launcher->launch([\PHP_BINARY, $binary, $name], $this->runtime->basePath, self::environment());

        if ($exit !== 0) {
            $output->warning(\sprintf('Scheduled command "%s" exited with code %d.', $name, $exit));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        $environment = [];

        foreach (getenv() as $name => $value) {
            $environment[(string) $name] = $value;
        }

        return $environment;
    }
}
