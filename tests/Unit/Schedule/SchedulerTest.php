<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Schedule;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobOptions;
use Trunk\Schedule\ScheduledTask;
use Trunk\Schedule\Scheduler;

final class SchedulerTest extends TestCase
{
    public function test_job_and_command_register_the_task_and_return_the_same_instance_to_chain_a_frequency_on(): void
    {
        // Arrange
        $scheduler = new Scheduler();
        $job = new class implements Job {
            public function handle(): void {}

            public static function options(): JobOptions
            {
                return new JobOptions();
            }
        };

        // Act
        $jobTask = $scheduler->job($job)->hourly();
        $commandTask = $scheduler->command('auth:prune')->daily('03:00');

        // Assert
        self::assertSame([$jobTask, $commandTask], $scheduler->tasks());
        self::assertInstanceOf(ScheduledTask::class, $jobTask);
        self::assertSame($job, $jobTask->job);
        self::assertNull($jobTask->command);
        self::assertSame('auth:prune', $commandTask->command);
        self::assertNull($commandTask->job);
    }

    public function test_a_task_defaults_to_every_minute_until_a_frequency_is_chosen(): void
    {
        // Arrange
        $scheduler = new Scheduler();
        $task = $scheduler->command('cache:clear');

        // Act & Assert
        self::assertTrue($task->isDue(new DateTimeImmutable('@0')));
        self::assertSame('every minute', $task->describe());
    }

    public function test_label_names_the_job_class_or_the_command(): void
    {
        // Arrange
        $scheduler = new Scheduler();
        $job = new class implements Job {
            public function handle(): void {}

            public static function options(): JobOptions
            {
                return new JobOptions();
            }
        };

        // Act
        $jobTask = $scheduler->job($job);
        $commandTask = $scheduler->command('auth:prune');

        // Assert
        self::assertSame($job::class, $jobTask->label());
        self::assertSame('auth:prune', $commandTask->label());
    }
}
