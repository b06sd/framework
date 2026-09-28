<?php

declare(strict_types=1);

namespace Trunk\Schedule\Console;

use Closure;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\CommandFailedException;
use Trunk\Schedule\ScheduledTask;
use Trunk\Schedule\Scheduler;

/**
 * Loads app/Schedule.php, a file the application owns:
 * `return static function (Scheduler $schedule): void { ... };`. Shared by schedule:run and
 * schedule:list, the same closure-return contract as Trunk\Router\Definition\RouteFile.
 *
 * @internal
 */
final class ScheduleFile
{
    /**
     * @return list<ScheduledTask>|null null when the file does not exist (already reported)
     */
    public static function load(string $path, CommandOutput $output): ?array
    {
        if (!is_file($path)) {
            $output->info('No app/Schedule.php. Create it: `return static function (Scheduler $schedule): void { ... };`.');

            return null;
        }

        $define = (static fn(string $file): mixed => require $file)($path);

        if (!$define instanceof Closure) {
            throw new CommandFailedException('app/Schedule.php must return a function: `return static function (Scheduler $schedule): void { ... };`.');
        }

        $scheduler = new Scheduler();
        $define($scheduler);

        return $scheduler->tasks();
    }
}
