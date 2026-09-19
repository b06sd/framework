<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Support\KernelHarness;

/**
 * Real HttpKernel, real logger: what a client sees, what the log says, in development and compiled
 * mode, in production and development.
 */
final class ErrorPipelineTest extends TestCase
{
    private string $logs;

    private KernelHarness $harness;

    protected function setUp(): void
    {
        $this->logs = sys_get_temp_dir() . '/trunk-errors-' . bin2hex(random_bytes(4));
        $this->harness = new KernelHarness(modules: [LoggingModule::class, DiagnosticsModule::class, HttpModule::class, WebModule::class], configuration: ['logging' => ['level' => 'debug', 'channel' => 'file', 'format' => 'json', 'path' => $this->logs, 'service' => 'shop']]);
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
    public function test_an_internal_error_is_a_generic_json_500_with_a_request_id_that_matches_the_log(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode);

        // Act
        $response = $this->get($kernel, '/boom', ['Accept' => 'application/json']);
        $body = json_decode((string) $response->getBody(), true, 8, JSON_THROW_ON_ERROR);
        $records = $this->records();

        // Assert
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertIsArray($body);
        self::assertSame(['error' => ['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.', 'requestId' => $response->getHeaderLine('X-Request-Id')]], $body);
        self::assertStringNotContainsString('secret database password', (string) $response->getBody());
        self::assertCount(1, $records);
        self::assertSame(['ERROR', $response->getHeaderLine('X-Request-Id'), 'shop', 'production', 500], [$records[0]['level'], $records[0]['requestId'], $records[0]['service'], $records[0]['environment'], $records[0]['status']]);
        self::assertIsArray($records[0]['exception']);
        self::assertSame(RuntimeException::class, self::at($records[0], 'exception', 'class'));
    }

    #[DataProvider('modes')]
    public function test_public_errors_carry_their_stable_code_in_every_format(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode);

        // Act
        $json = json_decode((string) $this->get($kernel, '/missing', ['Accept' => 'application/json'])->getBody(), true, 8, JSON_THROW_ON_ERROR);
        $html = $this->get($kernel, '/missing', ['Accept' => 'text/html,application/xhtml+xml']);
        $text = $this->get($kernel, '/missing');
        $wrongMethod = $this->get($kernel, '/pages/7', ['Accept' => 'application/json']);

        // Assert
        self::assertIsArray($json);
        self::assertSame('ROUTE_NOT_FOUND', self::at($json, 'error', 'code'));
        self::assertSame('Not Found', self::at($json, 'error', 'message'));
        self::assertSame('text/html; charset=utf-8', $html->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<h1>404 Not Found</h1>', (string) $html->getBody());
        self::assertStringContainsString($html->getHeaderLine('X-Request-Id'), (string) $html->getBody());
        self::assertStringContainsString("default-src 'none'", $html->getHeaderLine('Content-Security-Policy'));
        self::assertSame(['text/plain; charset=utf-8', 'Not Found'], [$text->getHeaderLine('Content-Type'), (string) $text->getBody()]);
        self::assertSame(200, $wrongMethod->getStatusCode());
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function formats(): iterable
    {
        yield 'json' => ['compiled', ['Accept' => 'application/json']];
        yield 'html' => ['compiled', ['Accept' => 'text/html']];
        yield 'text' => ['compiled', []];
        yield 'json body' => ['compiled', ['Content-Type' => 'application/json']];
        yield 'ajax' => ['development', ['X-Requested-With' => 'XMLHttpRequest']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('formats')]
    public function test_production_responses_never_contain_internals_in_any_format(string $mode, array $headers): void
    {
        // Arrange
        $kernel = $this->kernel($mode);

        // Act
        $body = (string) $this->get($kernel, '/boom', $headers)->getBody();

        // Assert
        foreach (['secret database password', 'RuntimeException', 'PageController', '.php', 'Stack trace', '#0', \dirname(__DIR__, 3), 'password'] as $leak) {
            self::assertStringNotContainsString($leak, $body, $leak);
        }
    }

    #[DataProvider('modes')]
    public function test_development_mode_shows_the_exception_in_each_format(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode, true);

        // Act
        $json = json_decode((string) $this->get($kernel, '/boom', ['Accept' => 'application/json'])->getBody(), true, 16, JSON_THROW_ON_ERROR);
        $html = (string) $this->get($kernel, '/boom', ['Accept' => 'text/html', 'Authorization' => 'Bearer super-secret-token'])->getBody();
        $text = (string) $this->get($kernel, '/boom')->getBody();

        // Assert
        self::assertIsArray($json);
        self::assertSame(RuntimeException::class, self::at($json, 'error', 'debug', 'exception'));
        self::assertSame('secret database password', self::at($json, 'error', 'debug', 'message'));
        self::assertStringContainsString('PageController.php', (string) json_encode(self::at($json, 'error', 'debug', 'file')));
        self::assertStringContainsString('RuntimeException', $html);
        self::assertStringContainsString('class="cur"', $html);
        self::assertStringContainsString('[REDACTED]', $html);
        self::assertStringNotContainsString('super-secret-token', $html);
        self::assertStringContainsString('RuntimeException: secret database password', $text);
    }

    #[DataProvider('modes')]
    public function test_inbound_request_and_trace_ids_are_used_only_when_they_are_safe(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode);
        $trace = '4bf92f3577b34da6a3ce929d0e0e4736';

        // Act
        $good = $this->get($kernel, '/boom', ['X-Request-Id' => 'gateway.abc-123456', 'traceparent' => '00-' . $trace . '-00f067aa0ba902b7-01']);
        $forged = $this->get($kernel, '/boom', ['X-Request-Id' => 'x" ERROR forged', 'traceparent' => 'garbage']);
        $records = $this->records();

        // Assert
        self::assertSame('gateway.abc-123456', $good->getHeaderLine('X-Request-Id'));
        self::assertMatchesRegularExpression('/^req_[0-9A-Z]{26}$/D', $forged->getHeaderLine('X-Request-Id'));
        self::assertSame(['gateway.abc-123456', $trace], [$records[0]['requestId'], $records[0]['traceId']]);
        self::assertSame($forged->getHeaderLine('X-Request-Id'), $records[1]['requestId']);
        self::assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/D', (string) json_encode($records[1]['traceId']));
        self::assertNotSame($records[0]['traceId'], $records[1]['traceId']);
    }

    #[DataProvider('modes')]
    public function test_every_response_carries_a_request_id_and_contexts_do_not_leak_between_requests(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode);

        // Act
        $ok = $this->get($kernel, '/pages/7');
        $again = $this->get($kernel, '/pages/7');

        // Assert
        self::assertNotSame('', $ok->getHeaderLine('X-Request-Id'));
        self::assertNotSame($ok->getHeaderLine('X-Request-Id'), $again->getHeaderLine('X-Request-Id'));
    }

    public function test_an_oversized_declared_body_is_a_413_through_the_normal_error_pipeline_before_any_route_runs(): void
    {
        // Arrange
        $kernel = $this->kernel('compiled');
        $previous = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/pages/7', 'HTTP_HOST' => 'trunk.dev', 'SERVER_PROTOCOL' => 'HTTP/1.1', 'CONTENT_LENGTH' => '999999999', 'HTTP_ACCEPT' => 'application/json'];

        // Act
        try {
            $kernel->run();
        } finally {
            $_SERVER = $previous;
        }
        $calls = $this->harness->sapi->calls;

        // Assert
        self::assertSame('status:HTTP/1.1 413 Content Too Large', $calls[0]);
        self::assertContains('write:Content Too Large', $calls, 'no request exists yet, so the safe plain-text form is used');
    }

    private function kernel(string $mode, bool $debug = false): HttpKernel
    {
        return $mode === 'compiled' ? $this->harness->compiled($debug ? Environment::Local : Environment::Production, $debug) : $this->harness->development($debug ? Environment::Local : Environment::Production, $debug);
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
     * @return list<array<string, mixed>>
     */
    private function records(): array
    {
        $records = [];

        foreach (glob($this->logs . '/*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $records[] = array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $records;
    }

    private static function at(mixed $value, string ...$path): mixed
    {
        foreach ($path as $key) {
            $value = \is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }
}
