<?php

declare(strict_types=1);

namespace Trunk\Schedule;

use Trunk\Queue\Job\Job;

/**
 * Collects the tasks app/Schedule.php defines:
 *
 *     return static function (Scheduler $schedule): void {
 *         $schedule->job(new CleanUpTempFiles())->daily();
 *         $schedule->command('auth:prune')->hourly();
 *     };
 *
 * `job()` dispatches through the queue when due; `command()` runs a registered console command by
 * name, in its own process. Both return the ScheduledTask so a frequency method can follow.
 *
 * @api
 */
final class Scheduler
{
    /** @var list<ScheduledTask> */
    private array $tasks = [];

    public function job(Job $job): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask(job: $job);
    }

    public function command(string $name): ScheduledTask
    {
        return $this->tasks[] = new ScheduledTask(command: $name);
    }

    /** @return list<ScheduledTask> */
    public function tasks(): array
    {
        return $this->tasks;
    }
}
