<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\Health\HealthModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Support\Directory;
use Trunk\Telemetry\TelemetryModule;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Support\KernelHarness;

/**
 * Health endpoints, metrics and tracing through the real kernel, in development and compiled mode.
 */
final class HealthAndObservabilityTest extends TestCase
{
    private string $logs;

    private KernelHarness $harness;

    protected function setUp(): void
    {
        $this->logs = sys_get_temp_dir() . '/trunk-health-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
        new Directory()->remove($this->logs);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'compiled' => ['compiled'];
        yield 'development' => ['development'];
    }

    #[DataProvider('modes')]
    public function test_liveness_never_touches_dependencies_and_readiness_reports_each_check(string $mode): void
    {
        // Arrange
        $this->build();
        $kernel = $this->kernel($mode);

        // Act
        $live = $this->get($kernel, '/health/live');
        $ready = $this->get($kernel, '/health/ready');

        // Assert
        self::assertSame([200, ['status' => 'ok']], [$live->getStatusCode(), $this->json($live)]);
        self::assertSame([200, ['status' => 'ok', 'checks' => ['database' => 'up']]], [$ready->getStatusCode(), $this->json($ready)]);
        self::assertSame('no-store', $ready->getHeaderLine('Cache-Control'));
    }

    #[DataProvider('modes')]
    public function test_a_down_dependency_is_503_but_liveness_stays_up_and_no_reason_leaks_in_production(string $mode): void
    {
        // Arrange
        $this->build(connection: ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'nope', 'username' => 'root-user', 'password' => 'hunter2-password']);
        $kernel = $this->kernel($mode);

        // Act
        $live = $this->get($kernel, '/health/live');
        $ready = $this->get($kernel, '/health/ready');
        $body = (string) $ready->getBody();

        // Assert
        self::assertSame(200, $live->getStatusCode());
        self::assertSame(503, $ready->getStatusCode());
        self::assertSame(['status' => 'unavailable', 'checks' => ['database' => 'down']], $this->json($ready));
        foreach (['hunter2', 'root-user', 'ConnectionException', '127.0.0.1', 'nope'] as $leak) {
            self::assertStringNotContainsString($leak, $body);
        }
    }

    #[DataProvider('modes')]
    public function test_the_metrics_endpoint_is_off_without_a_token_and_needs_the_right_one_with_it(string $mode): void
    {
        // Arrange
        $this->build(['metrics_token' => 'correct-horse-battery']);
        $kernel = $this->kernel($mode);
        $this->get($kernel, '/pages/7');
        $this->get($kernel, '/missing');

        // Act
        $none = $this->get($kernel, '/metrics');
        $wrong = $this->get($kernel, '/metrics', ['Authorization' => 'Bearer wrong']);
        $basic = $this->get($kernel, '/metrics', ['Authorization' => 'Basic correct-horse-battery']);
        $right = $this->get($kernel, '/metrics', ['Authorization' => 'Bearer correct-horse-battery']);
        $body = (string) $right->getBody();

        // Assert
        self::assertSame([401, 401, 401], [$none->getStatusCode(), $wrong->getStatusCode(), $basic->getStatusCode()]);
        self::assertSame('Bearer', $none->getHeaderLine('WWW-Authenticate'));
        self::assertSame(200, $right->getStatusCode());
        self::assertStringContainsString('text/plain; version=0.0.4', $right->getHeaderLine('Content-Type'));
        self::assertStringContainsString('# TYPE http_requests_total counter', $body);
        self::assertMatchesRegularExpression('/http_requests_total\{method="GET",route="[^"]*PageController::show",status_class="2xx"\} 1/', $body);
        self::assertStringContainsString('http_requests_total{method="GET",route="unmatched",status_class="4xx"} 1', $body);
        self::assertStringContainsString('http_request_duration_seconds_bucket{method="GET",route=', $body);
        self::assertStringNotContainsString('/pages/7', $body, 'raw paths are never metric labels');
        self::assertStringNotContainsString('/missing', $body);
    }

    #[DataProvider('modes')]
    public function test_the_metrics_endpoint_is_a_404_when_no_token_is_configured(string $mode): void
    {
        // Arrange
        $this->build();
        $kernel = $this->kernel($mode);

        // Act & Assert
        self::assertSame(404, $this->get($kernel, '/metrics', ['Authorization' => 'Bearer '])->getStatusCode());
        self::assertSame(404, $this->get($kernel, '/metrics')->getStatusCode());
    }

    #[DataProvider('modes')]
    public function test_tracing_writes_one_structured_span_record_per_request_with_the_request_trace_id(string $mode): void
    {
        // Arrange
        $this->build(tracing: true);
        $kernel = $this->kernel($mode);

        // Act
        $response = $this->get($kernel, '/pages/7', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);
        $records = [];
        foreach (glob($this->logs . '/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                $records[] = \is_array($record) ? $record : [];
            }
        }
        $spans = array_values(array_filter($records, static fn(array $r): bool => ($r['message'] ?? '') === 'span'));

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $spans);
        self::assertSame(['http.request', '4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', 'ok'], [$spans[0]['name'], $spans[0]['traceId'], $spans[0]['parentSpanId'], $spans[0]['status']]);
        self::assertSame($response->getHeaderLine('X-Request-Id'), $spans[0]['requestId']);
        $attributes = \is_array($spans[0]['attributes'] ?? null) ? $spans[0]['attributes'] : [];
        self::assertSame(200, $attributes['http.status_code'] ?? null);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', \is_string($spans[0]['spanId'] ?? null) ? $spans[0]['spanId'] : '');
    }

    /**
     * @param array<string, mixed> $health
     * @param array<string, mixed> $connection
     */
    private function build(array $health = [], array $connection = ['driver' => 'sqlite', 'database' => ':memory:'], bool $tracing = false): void
    {
        $this->harness = new KernelHarness(
            modules: [LoggingModule::class, DiagnosticsModule::class, TelemetryModule::class, DatabaseModule::class, HttpModule::class, HealthModule::class, WebModule::class],
            configuration: [
                'logging' => ['level' => 'debug', 'channel' => 'file', 'format' => 'json', 'path' => $this->logs, 'service' => 'shop'],
                'observability' => ['tracing' => $tracing, 'metrics' => true],
                'health' => $health + ['metrics_token' => '', 'debug' => false],
                'database' => ['default' => 'main', 'log_queries' => false, 'migrations' => $this->logs, 'connections' => ['main' => $connection]],
            ],
        );
    }

    private function kernel(string $mode, bool $debug = false): HttpKernel
    {
        $environment = $debug ? Environment::Local : Environment::Production;

        return $mode === 'compiled' ? $this->harness->compiled($environment, $debug) : $this->harness->development($environment, $debug);
    }

    /**
     * @param array<string, string> $headers
     */
    private function get(HttpKernel $kernel, string $path, array $headers = []): ResponseInterface
    {
        $request = new ServerRequest('GET', 'http://trunk.dev' . $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $kernel->handle($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);

        return \is_array($decoded) ? $decoded : [];
    }
}
