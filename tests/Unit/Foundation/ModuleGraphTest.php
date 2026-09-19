<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use Trunk\Application\Application;
use Trunk\Compiler\Build\BuildRunner;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Manifest\ModuleGraph;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\Graph\AfterBaseModule;
use Trunk\Tests\Fixtures\Modules\Graph\BaseModule;
use Trunk\Tests\Fixtures\Modules\Graph\CycleAModule;
use Trunk\Tests\Fixtures\Modules\Graph\CycleBModule;
use Trunk\Tests\Fixtures\Modules\Graph\CycleCModule;
use Trunk\Tests\Fixtures\Modules\Graph\NeedsBaseModule;
use Trunk\Tests\Fixtures\Modules\Graph\NeedsBothModule;

final class ModuleGraphTest extends TestCase
{
    public function test_a_correctly_ordered_manifest_including_a_diamond_is_valid(): void
    {
        // Arrange
        $graph = new ModuleGraph();

        // Act & Assert
        self::assertSame([], $graph->errors([BaseModule::class, NeedsBaseModule::class, NeedsBothModule::class]));
        self::assertSame([], $graph->errors([BaseModule::class, AfterBaseModule::class]));
        self::assertSame([], $graph->errors([AfterBaseModule::class]), 'after() only orders modules that are present');
        self::assertSame([], $graph->errors([]));
    }

    public function test_a_module_class_that_does_not_exist_is_reported_not_fatal(): void
    {
        // Act
        $modules = ['Acme\\Gone\\GoneModule'];
        $errors = new ModuleGraph()->errors($modules);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('Acme\\Gone\\GoneModule is listed in trunk.php but the class does not exist', $errors[0]);
    }

    public function test_a_missing_dependency_is_reported_with_the_line_to_add(): void
    {
        // Arrange & Act
        $errors = new ModuleGraph()->errors([NeedsBaseModule::class]);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('requires ' . BaseModule::class . ', which is not in trunk.php', $errors[0]);
        self::assertStringContainsString('Fix: add \\' . BaseModule::class . '::class above \\' . NeedsBaseModule::class . '::class', $errors[0]);
    }

    public function test_the_wrong_order_is_reported_not_silently_fixed(): void
    {
        // Arrange & Act
        $errors = new ModuleGraph()->errors([NeedsBaseModule::class, BaseModule::class]);
        $afterErrors = new ModuleGraph()->errors([AfterBaseModule::class, BaseModule::class]);

        // Assert
        self::assertStringContainsString('must be listed before', $errors[0]);
        self::assertStringContainsString('Fix: move \\' . BaseModule::class . '::class above', $errors[0]);
        self::assertCount(1, $afterErrors);
    }

    public function test_cycles_of_any_length_are_detected_once_with_their_path(): void
    {
        // Arrange & Act
        $errors = new ModuleGraph()->errors([CycleAModule::class, CycleBModule::class, CycleCModule::class]);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('Circular module dependency: ' . CycleAModule::class . ' -> ' . CycleBModule::class . ' -> ' . CycleCModule::class . ' -> ' . CycleAModule::class, $errors[0]);
    }

    public function test_development_startup_and_the_build_both_refuse_an_invalid_manifest_with_every_problem(): void
    {
        // Arrange
        $manifest = new ModuleManifest([NeedsBothModule::class, NeedsBaseModule::class]);
        $runtime = new Runtime(Environment::Local, false, sys_get_temp_dir());
        $directory = sys_get_temp_dir() . '/trunk-graph-' . bin2hex(random_bytes(4));
        $application = new Application($runtime, new Configuration(), $manifest);
        $messages = [];

        // Act
        try {
            $application->register();
        } catch (ConfigurationException $e) {
            $messages[] = $e->getMessage();
        }

        try {
            new BuildRunner()->run($manifest, $runtime, [], $directory);
        } catch (CompilationException $e) {
            $messages[] = implode("\n", $e->errors);
        }

        new Directory()->remove($directory);

        // Assert
        self::assertCount(2, $messages);
        foreach ($messages as $message) {
            self::assertStringContainsString('requires ' . BaseModule::class, $message);
            self::assertStringContainsString('must be listed before', $message);
        }
    }
}
