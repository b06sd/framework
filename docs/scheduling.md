# Scheduling

`trunk package:install schedule` (it needs `queue`). Define recurring jobs and commands once, in code, the same way routes and jobs are each defined once — instead of one crontab line per task.

## Define what runs

```php
// app/Schedule.php
<?php

declare(strict_types=1);

use App\Jobs\CleanUpTempFiles;
use Trunk\Schedule\Scheduler;

return static function (Scheduler $schedule): void {
    $schedule->job(new CleanUpTempFiles())->daily('03:00');
    $schedule->command('auth:prune')->hourly();
};
```

`job()` dispatches through the queue when due, exactly like `Queue::dispatch()` — cheap, and it survives `schedule:run` itself being killed mid-pass. `command()` runs a registered console command by name, in its own process. Both return the task so a frequency can follow: `everyMinutes(int $n)`, `hourly()`, `daily(string $at = '00:00')`, `weekly(int $dayOfWeek, string $at = '00:00')` (`$dayOfWeek` matches `DateTimeImmutable::format('w')`: 0 Sunday to 6 Saturday). Every time is interpreted in UTC. There is deliberately no cron-string parsing — these five methods cover what a real app schedules, and a typo in a method name fails at compile time instead of silently never running.

## Deploy: one crontab line

```
* * * * * cd /app && php vendor/bin/trunk schedule:run >> /dev/null 2>&1
```

`schedule:run` checks every task once and exits; whichever are due this minute run, then it is done. Two overlapping passes cannot both run: a lock file (`storage/schedule.lock`) makes a second `schedule:run` skip immediately instead of double-running a task that is still due when it starts.

## See what is scheduled

```
$ trunk schedule:list
Task                        Frequency          Next run
CleanUpTempFiles             daily at 03:00 UTC  2026-01-02 03:00 UTC
auth:prune                   hourly, on the hour 2026-01-01 15:00 UTC
```

The direct fix for a scheduled task being invisible to everything else in the app: no more crontab entries a new developer has to go find and cross-reference against the code.

## Failure handling

A dispatched job follows the queue's own retry/backoff rules (see [Queue](queue.md)). A scheduled command that exits non-zero is reported as a warning in `schedule:run`'s output (visible in the cron log) and does not stop the other tasks in that pass.

Related: [Queue](queue.md), [CLI](cli.md).
