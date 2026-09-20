<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\Cli;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * What `trunk new` writes is the first code a developer reads and analyses, so it must pass the same
 * PHPStan level the framework itself does.
 */
final class GeneratedCodeAnalysisTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function profiles(): iterable
    {
        foreach (['api', 'web', 'self-contained', 'cli', 'worker'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('profiles')]
    public function test_a_new_project_passes_phpstan_at_the_maximum_level(string $type): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('analysed', $type);
        file_put_contents($project->directory . '/phpstan.neon', "parameters:\n    level: max\n    paths:\n        - app\n        - routes\n");
        $paths = array_filter(['app', 'routes'], static fn(string $p): bool => is_dir($project->directory . '/' . $p));
        file_put_contents($project->directory . '/phpstan.neon', "parameters:\n    level: max\n    paths:\n" . implode('', array_map(static fn(string $p): string => '        - ' . $p . "\n", $paths)));

        // Act
        [$code, $out, $err] = new Cli()->run([\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', '--error-format=raw'], $project->directory);

        // Assert
        self::assertSame(0, $code, $out . $err);
    }

    public function test_a_generated_entity_and_map_pass_phpstan_at_the_maximum_level(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('analysed', 'api');
        $project->trunk(['package:install', 'orm']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['make:entity', 'Customer']);
        file_put_contents($project->directory . '/phpstan.neon', "parameters:\n    level: max\n    paths:\n        - app\n");

        // Act
        [$code, $out, $err] = new Cli()->run([\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/phpstan', 'analyse', '--no-progress', '--memory-limit=1G', '--error-format=raw'], $project->directory);

        // Assert
        self::assertFileExists($project->directory . '/app/Entities/Customer.php');
        self::assertSame(0, $code, $out . $err);
    }
}
