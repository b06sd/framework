<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * `trunk doctor` also checks the database, not only files and config: a capability that is enabled
 * but never migrated is caught before it becomes a 500 on the first real request. Reproduces the
 * exact scenario docs/ELEGANCE_REVIEW.md found: `package:install queue`, dispatch code, no
 * `queue:table`, and `doctor` used to say "Project is healthy" anyway.
 */
final class DoctorRuntimeChecksTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_doctor_reports_a_missing_queue_table_and_stops_once_it_is_migrated(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);

        // Act
        [$beforeCode, $beforeOut] = $project->trunk(['doctor']);
        $project->trunk(['queue:table']);
        [$migrateCode] = $project->trunk(['migrate']);
        [$afterCode, $afterOut] = $project->trunk(['doctor']);

        // Assert
        self::assertNotSame(0, $beforeCode, $beforeOut);
        self::assertStringContainsString('Queue tables', $beforeOut);
        self::assertStringContainsString('trunk_jobs', $beforeOut);
        self::assertStringContainsString('trunk queue:table && trunk migrate', $beforeOut);
        self::assertSame(0, $migrateCode);
        self::assertSame(0, $afterCode, $afterOut);
        self::assertStringContainsString('Queue tables', $afterOut);
        self::assertStringContainsString('Project is healthy.', $afterOut);
    }

    public function test_doctor_reports_a_missing_auth_table_and_stops_once_it_is_migrated(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'database']);
        $project->trunk(['package:install', 'auth']);
        $project->trunk(['package:install', 'console']);

        // Act
        [$beforeCode, $beforeOut] = $project->trunk(['doctor']);
        $project->trunk(['auth:table']);
        $project->trunk(['migrate']);
        [$afterCode, $afterOut] = $project->trunk(['doctor']);

        // Assert
        self::assertNotSame(0, $beforeCode, $beforeOut);
        self::assertStringContainsString('Auth tables', $beforeOut);
        self::assertStringContainsString('trunk_sessions', $beforeOut);
        self::assertSame(0, $afterCode, $afterOut);
    }

    public function test_doctor_still_works_and_skips_runtime_checks_when_the_app_cannot_boot(): void
    {
        // Arrange: an api profile has no diagnostics-registered DoctorChecks dependency issue by
        // itself, so break the app a different way: corrupt trunk.php's config so boot fails.
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        file_put_contents($project->directory . '/config/app.php', "<?php\nreturn 'not an array';\n");

        // Act
        [$code, $out, $err] = $project->trunk(['doctor']);

        // Assert: the existing configuration check already reports this; doctor does not crash
        self::assertNotSame(0, $code);
        self::assertStringContainsString('Configuration (config/*.php)', $out . $err);
    }
}
