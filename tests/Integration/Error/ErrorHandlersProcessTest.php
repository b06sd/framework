<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Error;

use PHPUnit\Framework\TestCase;
use Trunk\Support\Directory;
use Trunk\Tests\Support\Cli;

/**
 * Real PHP processes: what happens to a warning, an uncaught exception, a compile error and memory
 * exhaustion when Trunk's handlers are registered.
 */
final class ErrorHandlersProcessTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-handlers-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_memory_exhaustion_leaves_an_emergency_line_and_a_generic_500_with_the_request_id(): void
    {
        // Arrange & Act
        [$code, $out, $err] = $this->execute('oom');
        $emergency = $this->lines('emergency.log');

        // Assert
        self::assertSame(255, $code);
        self::assertStringContainsString('Internal Server Error', $out);
        self::assertStringContainsString('req_TESTREQUEST0001', $out);
        self::assertCount(1, $emergency);
        self::assertSame(['CRITICAL', 'Fatal error.', 'memory limit exhausted', 'req_TESTREQUEST0001', 'http'], [$emergency[0]['level'], $emergency[0]['message'], $emergency[0]['error'], $emergency[0]['requestId'], $emergency[0]['kind']]);
        self::assertGreaterThan(0, $emergency[0]['peak_mb']);
        self::assertSame(32768, $emergency[0]['reserve_freed_bytes']);
        self::assertStringNotContainsString('xxxxxxxx', $out . $err);
    }

    public function test_a_compile_error_is_captured_without_echoing_php_internals_to_the_client(): void
    {
        // Arrange & Act
        [$code, $out] = $this->execute('compile');
        $emergency = $this->lines('emergency.log');

        // Assert
        self::assertSame(255, $code);
        self::assertStringContainsString('Internal Server Error', $out);
        self::assertCount(1, $emergency);
        self::assertStringContainsString('redeclare', (string) json_encode($emergency[0]['error']));
    }

    public function test_an_uncaught_exception_is_logged_and_the_console_shows_only_a_generic_message(): void
    {
        // Arrange & Act
        [$code, $out, $err] = $this->execute('uncaught');
        $records = $this->lines($this->logFile());

        // Assert
        self::assertSame(255, $code);
        self::assertStringContainsString('An unexpected error occurred. Request ID: req_TESTREQUEST0001', $err);
        self::assertStringNotContainsString('hunter2', $out . $err);
        self::assertCount(1, $records);
        self::assertSame('Unhandled exception.', $records[0]['message']);
        self::assertSame('req_TESTREQUEST0001', $records[0]['requestId']);
        self::assertStringContainsString('hunter2', (string) file_get_contents($this->directory . '/' . $this->logFile()), 'the operator log keeps the message; only clients and consoles are kept generic');
    }

    public function test_warnings_are_logged_and_survived_in_production_and_thrown_in_development(): void
    {
        // Arrange & Act
        [$prodCode, $prodOut] = $this->execute('warning-prod');
        $records = $this->lines($this->logFile());
        [$devCode, $devOut] = $this->execute('warning-dev');

        // Assert
        self::assertSame(0, $prodCode);
        self::assertStringEndsWith('continued', $prodOut);
        self::assertStringNotContainsString('Warning:', $prodOut, 'no raw PHP warning is shown to the user');
        self::assertNotEmpty(array_filter($records, static fn(array $r): bool => str_contains((string) json_encode($r['message']), 'a harmless warning')));
        self::assertSame(0, $devCode);
        self::assertSame('caught:a strict warning', $devOut);
    }

    public function test_deprecations_are_logged_at_notice_and_never_thrown(): void
    {
        // Arrange & Act
        [$code, $out] = $this->execute('deprecation');
        $records = $this->lines($this->logFile());

        // Assert
        self::assertSame([0, 'continued'], [$code, $out]);
        self::assertSame('NOTICE', $records[0]['level']);
    }

    /**
     * @return array{int, string, string}
     */
    private function execute(string $scenario): array
    {
        return new Cli()->run([\PHP_BINARY, '-d', 'display_errors=1', __DIR__ . '/../../Support/error_handlers_script.php', $scenario, $this->directory], $this->directory);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lines(string $file): array
    {
        $records = [];

        foreach (file($this->directory . '/' . $file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $records[] = array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY);
        }

        return $records;
    }

    private function logFile(): string
    {
        foreach (glob($this->directory . '/trunk-*.log') ?: [] as $file) {
            return basename($file);
        }

        return 'trunk-none.log';
    }
}
