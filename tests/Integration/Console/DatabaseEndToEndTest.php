<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary against a scaffolded project: enable the capabilities, create and run a
 * migration against a real SQLite file, then prove the production guard after `trunk build`.
 */
final class DatabaseEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_migrations_run_from_the_cli_and_migrate_fresh_is_refused_in_production(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        self::assertStringNotContainsString('migrate', $project->trunk(['list'])[1], 'Without the database capability there are no migrate commands.');

        // Act
        [$installCode] = $project->trunk(['package:install', 'database']);
        [$consoleCode] = $project->trunk(['package:install', 'console']);
        [$makeCode, $makeOut] = $project->trunk(['make:migration', 'create_customers_table']);
        $files = glob($project->directory . '/database/migrations/*_create_customers_table.php') ?: [];
        [$migrateCode, $migrateOut] = $project->trunk(['migrate']);
        [, $statusOut] = $project->trunk(['migrate:status']);
        [$buildCode] = $project->trunk(['build']);
        [$freshCode, $freshOut, $freshErr] = $project->trunk(['migrate:fresh'], null, ['APP_ENV' => 'production']);
        [$badStepCode] = $project->trunk(['migrate:rollback', '--step=x'], null, ['APP_ENV' => 'production']);
        [$rollbackCode, $rollbackOut] = $project->trunk(['migrate:rollback'], null, ['APP_ENV' => 'production']);
        [$badNameCode] = $project->trunk(['make:migration', '../evil']);

        // Assert
        self::assertSame(0, $installCode);
        self::assertSame(0, $consoleCode);
        self::assertSame(0, $makeCode, $makeOut);
        self::assertCount(1, $files);
        self::assertSame(0, $migrateCode, $migrateOut);
        self::assertStringContainsString('create_customers_table', $migrateOut);
        self::assertStringContainsString('Ran', $statusOut);
        self::assertSame(0, $buildCode);
        self::assertNotSame(0, $freshCode);
        self::assertStringContainsString('disabled in production', $freshOut . $freshErr);
        self::assertNotSame(0, $badStepCode);
        self::assertSame(0, $rollbackCode, $rollbackOut);
        self::assertStringContainsString('rolled back', $rollbackOut);
        self::assertNotSame(0, $badNameCode);
        self::assertFileDoesNotExist($project->directory . '/database/evil.php');
    }
}
