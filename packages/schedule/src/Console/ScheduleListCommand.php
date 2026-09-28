<?php

declare(strict_types=1);

namespace Trunk\Schedule\Console;

use DateTimeImmutable;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Foundation\Runtime;
use Trunk\Schedule\ScheduledTask;

/**
 * `trunk schedule:list` shows every task app/Schedule.php defines, how often it runs and when it
 * next runs — the direct fix for a scheduled task being invisible to everything else in the app.
 */
final readonly class ScheduleListCommand implements Command
{
    public function __construct(private Runtime $runtime, private Clock $clock) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('schedule:list', 'Show every scheduled task and when it next runs');
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        $tasks = ScheduleFile::load($this->runtime->basePath . '/app/Schedule.php', $output);

        if ($tasks === null) {
            return 0;
        }

        if ($tasks === []) {
            $output->info('No tasks defined in app/Schedule.php.');

            return 0;
        }

        $now = new DateTimeImmutable('@' . $this->clock->now());
        $rows = array_map(static fn(ScheduledTask $task): array => [$task->label(), $task->describe(), self::next($task, $now)->format('Y-m-d H:i') . ' UTC'], $tasks);

        $output->table(['Task', 'Frequency', 'Next run'], $rows);

        return 0;
    }

    /** A minute-by-minute search is fine here: schedule:list is run by a developer, not a hot path. */
    private static function next(ScheduledTask $task, DateTimeImmutable $after): DateTimeImmutable
    {
        $candidate = $after;

        for ($i = 0; $i < 60 * 24 * 8; $i++) {
            $candidate = $candidate->modify('+1 minute');

            if ($task->isDue($candidate)) {
                return $candidate;
            }
        }

        return $candidate;
    }
}
