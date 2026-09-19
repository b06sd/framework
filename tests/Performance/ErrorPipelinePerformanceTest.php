<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Logging\ContextNormalizer;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\NullHandler;
use Trunk\Logging\Redactor;
use Trunk\Logging\StructuredLogger;
use Trunk\Support\Directory;
use Trunk\Telemetry\TelemetryModule;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Support\KernelHarness;

/**
 * The normal request path must stay cheap with the error pipeline, request context, logging and
 * lifecycle cleanup in place. Timings are printed to stderr and bounded loosely.
 */
final class ErrorPipelinePerformanceTest extends TestCase
{
    public function test_the_happy_path_pays_almost_nothing_for_the_diagnostics(): void
    {
        // Arrange
        $bare = new KernelHarness(modules: [HttpModule::class, WebModule::class]);
        $logs = sys_get_temp_dir() . '/trunk-perf-' . bin2hex(random_bytes(4));
        $full = new KernelHarness(modules: [LoggingModule::class, DiagnosticsModule::class, HttpModule::class, WebModule::class], configuration: ['logging' => ['level' => 'info', 'channel' => 'null']]);
        $n = 5000;

        // Act
        $plain = $this->time($bare->compiled(Environment::Production), '/pages/7', $n);
        $instrumented = $this->time($full->compiled(Environment::Production), '/pages/7', $n);
        $observed = new KernelHarness(modules: [LoggingModule::class, DiagnosticsModule::class, TelemetryModule::class, HttpModule::class, WebModule::class], configuration: ['logging' => ['level' => 'info', 'channel' => 'null'], 'observability' => ['metrics' => true, 'tracing' => true]]);
        $withMetrics = $this->time($observed->compiled(Environment::Production), '/pages/7', $n);
        $observed->cleanUp();
        $errors = $this->time($full->compiled(Environment::Production), '/boom', 2000);
        $bare->cleanUp();
        $full->cleanUp();
        new Directory()->remove($logs);

        // Assert
        $this->report('request, no logging capability', $plain, $n);
        $this->report('request, request-id + lifecycle + logger', $instrumented, $n);
        $this->report('request, + metrics and span logging on', $withMetrics, $n);
        $this->report('failing request (log + generic 500)', $errors, 2000);
        self::assertLessThan(($plain / $n) + 0.0003, $instrumented / $n, 'the request context, lifecycle cleanup and request id add less than 0.3 ms per request');
    }

    public function test_the_logger_is_fast_and_records_below_the_threshold_cost_nearly_nothing(): void
    {
        // Arrange
        $logger = new StructuredLogger(new JsonFormatter(), new NullHandler(), 'info', null, 'svc', 'production', new ContextNormalizer(new Redactor()));
        $context = ['workflowId' => 1842, 'userId' => 7, 'status' => 'running', 'password' => 'x', 'nested' => ['a' => 1, 'b' => ['c' => 2]]];
        $n = 20000;

        // Act
        $start = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $logger->info('Workflow execution started', $context);
        }
        $written = (hrtime(true) - $start) / 1e9;
        $start = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $logger->debug('below the threshold', $context);
        }
        $skipped = (hrtime(true) - $start) / 1e9;

        // Assert
        $this->report('logger.info (normalise, redact, JSON)', $written, $n);
        $this->report('logger.debug below threshold', $skipped, $n);
        self::assertLessThan($written / 5, $skipped, 'filtered records must be far cheaper than written ones');
    }
    private function report(string $label, float $seconds, int $count): void
    {
        fwrite(\STDERR, \sprintf("\n[errors-perf] %-46s %8.1f ms  (%d ops, %.1f us/op)", $label, $seconds * 1000, $count, $seconds / max(1, $count) * 1e6));
    }

    private function time(HttpKernel $kernel, string $path, int $n): float
    {
        for ($i = 0; $i < 200; ++$i) {
            $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path));
        }

        $start = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path));
        }

        return (hrtime(true) - $start) / 1e9;
    }
}
