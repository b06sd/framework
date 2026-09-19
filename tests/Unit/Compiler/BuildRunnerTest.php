<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildRunner;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\CompilableModule;
use Trunk\Tests\Fixtures\Modules\ContributingModule;
use Trunk\Tests\Fixtures\Modules\FailingContributorModule;

final class BuildRunnerTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/trunk-runner-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->base);
    }

    public function test_contributions_are_planned_first_and_written_together_with_the_container_and_config(): void
    {
        // Arrange
        $build = $this->base . '/build';
        $manifest = new ModuleManifest([ContributingModule::class]);

        // Act
        $report = new BuildRunner()->run($manifest, $this->runtime(), ['app' => ['name' => 'X']], $build);

        // Assert
        self::assertSame([ContributingModule::class], $report->contributors);
        self::assertSame(['Trunk\\Tests\\Fixtures\\Di\\Token'], $report->autoRegistered);
        self::assertFileExists($build . '/container.php');
        self::assertFileExists($build . '/modules.php');
        self::assertSame('from production', file_get_contents($build . '/contributed.txt'));
        self::assertSame(['app' => ['name' => 'X']], require $build . '/config.php');
    }

    public function test_every_error_is_reported_together_and_nothing_is_written_when_anything_fails(): void
    {
        // Arrange
        $build = $this->base . '/build';
        $manifest = new ModuleManifest([FailingContributorModule::class, ContributingModule::class]);

        // Act
        try {
            new BuildRunner()->run($manifest, $this->runtime(), [], $build);
            self::fail('Expected a CompilationException.');
        } catch (CompilationException $e) {
            // Assert
            self::assertSame(['the contributor is unhappy'], $e->errors);
            self::assertDirectoryDoesNotExist($build);
            self::assertSame([], glob($this->base . '/build*') ?: []);
        }
    }

    public function test_a_new_build_replaces_the_old_one_so_stale_artifacts_disappear(): void
    {
        // Arrange
        $build = $this->base . '/build';
        new BuildRunner()->run(new ModuleManifest([ContributingModule::class]), $this->runtime(), [], $build);
        self::assertFileExists($build . '/contributed.txt');

        // Act
        new BuildRunner()->run(new ModuleManifest([CompilableModule::class]), $this->runtime(), [], $build);

        // Assert
        self::assertFileDoesNotExist($build . '/contributed.txt');
    }

    private function runtime(): Runtime
    {
        return new Runtime(Environment::Production, false, $this->base);
    }
}
