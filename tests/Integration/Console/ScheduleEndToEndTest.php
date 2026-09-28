<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary: schedule:list shows every task and when it next runs, and schedule:run
 * dispatches a due job through the real queue and runs a due command in its own process.
 */
final class ScheduleEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_schedule_list_and_schedule_run_dispatch_a_due_job_through_the_queue(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['package:install', 'schedule']);
        $project->trunk(['queue:table']);
        $project->trunk(['migrate']);
        $project->trunk(['make:job', 'WriteMarker']);
        file_put_contents($project->directory . '/app/Jobs/WriteMarker.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Jobs;\n\nuse Trunk\\Foundation\\Runtime;\nuse Trunk\\Queue\\Job\\Job;\nuse Trunk\\Queue\\Job\\JobOptions;\n\nfinal readonly class WriteMarker implements Job\n{\n    public function __construct(public int \$id, public string \$note = '') {}\n\n    public function handle(Runtime \$runtime): void\n    {\n        file_put_contents(\$runtime->basePath . '/storage/marker-' . \$this->id . '.txt', \$this->note);\n    }\n\n    public static function options(): JobOptions\n    {\n        return new JobOptions();\n    }\n}\n");
        file_put_contents($project->directory . '/app/Schedule.php', "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Jobs\\WriteMarker;\nuse Trunk\\Schedule\\Scheduler;\n\nreturn static function (Scheduler \$schedule): void {\n    \$schedule->job(new WriteMarker(5, 'scheduled'))->everyMinutes(1);\n    \$schedule->command('missing:command')->everyMinutes(1);\n};\n");

        // Act
        [$listCode, $listOut] = $project->trunk(['schedule:list']);
        [$runCode, $runOut] = $project->trunk(['schedule:run']);
        [$workCode, $workOut] = $project->trunk(['queue:work', '--stop-when-empty']);
        $marker = @file_get_contents($project->directory . '/storage/marker-5.txt');

        // Assert
        self::assertSame(0, $listCode, $listOut);
        self::assertStringContainsString('WriteMarker', $listOut);
        self::assertStringContainsString('missing:command', $listOut);
        self::assertStringContainsString('every minute', $listOut);
        self::assertSame(0, $runCode, $runOut);
        self::assertStringContainsString('2 task(s) ran.', $runOut, 'both tasks are due every minute');
        self::assertStringContainsString('Scheduled command "missing:command"', $runOut, 'a command that fails is still reported, not thrown');
        self::assertSame(0, $workCode, $workOut);
        self::assertSame('scheduled', $marker);
    }

    public function test_schedule_run_is_a_no_op_without_a_schedule_file_and_skips_an_overlapping_pass(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['package:install', 'schedule']);

        // Act: no app/Schedule.php yet
        [$noFileCode, $noFileOut] = $project->trunk(['schedule:run']);

        // Arrange: a schedule file, and a held lock simulating a still-running pass
        file_put_contents($project->directory . '/app/Schedule.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Trunk\\Schedule\\Scheduler;\n\nreturn static function (Scheduler \$schedule): void {\n    \$schedule->command('cache:clear')->everyMinutes(1);\n};\n");
        $lock = fopen($project->directory . '/storage/schedule.lock', 'c');
        self::assertNotFalse($lock);
        self::assertTrue(flock($lock, \LOCK_EX));

        // Act
        [$lockedCode, $lockedOut] = $project->trunk(['schedule:run']);

        // Cleanup
        flock($lock, \LOCK_UN);
        fclose($lock);

        // Assert
        self::assertSame(0, $noFileCode);
        self::assertStringContainsString('No app/Schedule.php', $noFileOut);
        self::assertSame(0, $lockedCode);
        self::assertStringContainsString('skipped', $lockedOut);
    }

    /**
     * The one scenario that needs a real `composer install`: proof that a scheduled command truly
     * runs in its own process, through vendor/bin/trunk, exactly as the one-line crontab entry would.
     */
    public function test_schedule_run_actually_executes_a_scheduled_command_in_its_own_process(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api', realInstall: true);
        $project->trunk(['package:install', 'cache']);
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['package:install', 'schedule']);
        file_put_contents($project->directory . '/app/Schedule.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Trunk\\Schedule\\Scheduler;\n\nreturn static function (Scheduler \$schedule): void {\n    \$schedule->command('cache:clear')->everyMinutes(1);\n};\n");

        // Act
        [$runCode, $runOut] = $project->trunk(['schedule:run']);

        // Assert
        self::assertSame(0, $runCode, $runOut);
        self::assertStringContainsString('1 task(s) ran.', $runOut);
        self::assertStringContainsString('Application cache cleared.', $runOut, 'cache:clear\'s own output, from a genuinely separate process');
    }
}
