<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Error\ExceptionHandler;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Foundation\Manifest\ModuleGraph;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Health\HealthChecker;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Logging\ContextHolder;
use Trunk\Observability\Metrics;
use Trunk\Observability\MetricsExporter;
use Trunk\Observability\Tracer;

/**
 * Logging is only logging; the error pipeline, lifecycle and health services are a separate module
 * that builds on it.
 */
final class DiagnosticsModuleTest extends TestCase
{
    public function test_the_logging_module_binds_a_logger_and_nothing_else_of_the_runtime(): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act
        new LoggingModule()->register($builder);

        // Assert
        self::assertTrue($builder->has(LoggerInterface::class));
        self::assertTrue($builder->has(ContextHolder::class));
        foreach ([ExceptionHandler::class, LifecycleManager::class, HealthChecker::class, Metrics::class, Tracer::class, MetricsExporter::class] as $id) {
            self::assertFalse(\array_key_exists($id, $builder->definitions()), $id . ' belongs to the diagnostics module');
        }
    }

    public function test_the_diagnostics_module_binds_the_error_pipeline_lifecycle_health_and_no_op_observability(): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act
        new LoggingModule()->register($builder);
        new DiagnosticsModule()->register($builder);
        $ids = array_keys($builder->definitions());

        // Assert
        foreach ([ExceptionHandler::class, LifecycleManager::class, HealthChecker::class, Metrics::class, Tracer::class, MetricsExporter::class] as $id) {
            self::assertContains($id, $ids);
        }
    }

    public function test_it_must_be_listed_after_the_logging_module_and_the_error_names_the_fix(): void
    {
        // Act
        $errors = new ModuleGraph()->errors([DiagnosticsModule::class]);
        $wrongOrder = new ModuleGraph()->errors([DiagnosticsModule::class, LoggingModule::class]);
        $fine = new ModuleGraph()->errors([LoggingModule::class, DiagnosticsModule::class]);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('requires ' . LoggingModule::class, $errors[0]);
        self::assertNotSame([], $wrongOrder);
        self::assertSame([], $fine);
    }

    public function test_the_error_format_is_validated_by_the_diagnostics_module_at_build_time(): void
    {
        // Arrange
        $context = static fn(array $errors): BuildContext => new BuildContext(new ModuleManifest([]), new Runtime(Environment::Production, false, sys_get_temp_dir()), new Configuration(['errors' => $errors]));

        // Act & Assert
        new DiagnosticsModule()->plan($context(['format' => 'html']));
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('errors.format must be auto, json, html or text (config/errors.php).');
        new DiagnosticsModule()->plan($context(['format' => 'xml']));
    }
}
