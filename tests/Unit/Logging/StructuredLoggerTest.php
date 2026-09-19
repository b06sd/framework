<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Logging;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\ContextNormalizer;
use Trunk\Logging\FileHandler;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\LineFormatter;
use Trunk\Logging\LoggerFactory;
use Trunk\Logging\LogHandler;
use Trunk\Logging\Redactor;
use Trunk\Logging\RequestContext;
use Trunk\Logging\StreamHandler;
use Trunk\Logging\StructuredLogger;
use Trunk\Support\Directory;

final class StructuredLoggerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-log-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_records_are_structured_json_with_the_documented_fields(): void
    {
        // Arrange
        $holder = new ContextHolder();
        $holder->set(new RequestContext('req_123', 'trace_456', 'span_1', 'http'));
        [$logger, $sink] = $this->logger(holder: $holder);

        // Act
        $logger->error('Workflow execution failed', ['workflowId' => 1842, 'exception' => new RuntimeException('boom')]);
        $record = $this->decode($sink->lines[0]);

        // Assert
        self::assertSame('2026-09-19T08:30:10.123Z', $record['timestamp']);
        self::assertSame(['ERROR', 'Workflow execution failed', 'req_123', 'trace_456', 'workflow-api', 'production', 1842], [$record['level'], $record['message'], $record['requestId'], $record['traceId'], $record['service'], $record['environment'], $record['workflowId']]);
        self::assertIsArray($record['exception']);
        self::assertSame(RuntimeException::class, $record['exception']['class'] ?? null);
    }

    public function test_the_level_threshold_filters_and_invalid_levels_are_rejected(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger(LogLevel::WARNING);

        // Act
        $logger->debug('no');
        $logger->info('no');
        $logger->warning('yes');
        $logger->critical('yes');

        // Assert
        self::assertCount(2, $sink->lines);
        self::assertTrue($logger->isEnabled('error'));
        self::assertFalse($logger->isEnabled('info'));
        $this->expectException(InvalidArgumentException::class);
        $logger->log('loud', 'x');
    }

    public function test_secrets_are_redacted_in_context_placeholders_and_messages(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger(redact: ['ssn']);

        // Act
        $logger->info('Login by {user} with {password} and token {token}', ['user' => 'ada', 'password' => 'hunter2', 'token' => 'tok_secret', 'ssn' => '123-45-6789', 'nested' => ['Authorization' => 'Bearer abcdefghijkl']]);
        $logger->info('failed: password=hunter2 Bearer abcdefghijklmnop');
        $all = implode('', $sink->lines);

        // Assert
        self::assertStringNotContainsString('hunter2', $all);
        self::assertStringNotContainsString('tok_secret', $all);
        self::assertStringNotContainsString('123-45-6789', $all);
        self::assertStringNotContainsString('abcdefghijkl', $all);
        self::assertSame('Login by ada with [REDACTED] and token [REDACTED]', $this->decode($sink->lines[0])['message']);
    }

    public function test_log_injection_cannot_forge_records_or_smuggle_control_characters(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger();

        // Act
        $logger->info("login ok\n{\"level\":\"CRITICAL\",\"message\":\"forged\"}", ["evil\nkey" => "v\r\n2026 ERROR forged", 'ansi' => "\x1b[31mred", 'name' => "Ada\u{202E}"]);

        // Assert
        self::assertCount(1, $sink->lines);
        $record = $this->decode($sink->lines[0]);
        self::assertSame('INFO', $record['level']);
        self::assertStringNotContainsString("\x1b", $sink->lines[0]);
        self::assertStringNotContainsString("\r", $sink->lines[0]);
        self::assertStringNotContainsString("\u{202E}", $sink->lines[0]);
    }

    public function test_caller_context_cannot_overwrite_the_reserved_fields(): void
    {
        // Arrange
        [$logger, $sink] = $this->logger();

        // Act
        $logger->info('real', ['level' => 'EMERGENCY', 'message' => 'fake', 'timestamp' => 'yesterday', 'service' => 'other']);
        $record = $this->decode($sink->lines[0]);

        // Assert
        self::assertSame(['INFO', 'real', 'workflow-api', '2026-09-19T08:30:10.123Z'], [$record['level'], $record['message'], $record['service'], $record['timestamp']]);
        self::assertSame('EMERGENCY', $record['level_']);
    }

    public function test_a_failing_handler_never_throws_into_the_application_and_falls_back_to_error_log(): void
    {
        // Arrange
        mkdir($this->directory);
        $fallback = $this->directory . '/php-error.log';
        $previous = ini_set('error_log', $fallback);
        $logger = new StructuredLogger(new JsonFormatter(), new class implements LogHandler {
            public function write(string $line): void
            {
                throw new RuntimeException('disk full');
            }
        });

        // Act
        try {
            $logger->error('still fine');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        // Assert
        self::assertStringContainsString('the log record could not be written (error)', (string) file_get_contents($fallback));
        self::assertStringNotContainsString('still fine', (string) file_get_contents($fallback), 'the message itself is not echoed to the fallback');
    }

    public function test_the_file_handler_writes_one_file_per_utc_day_with_a_fixed_name(): void
    {
        // Arrange
        $handler = new FileHandler($this->directory . '/logs', static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-19 23:59:59 America/New_York'));
        $logger = new StructuredLogger(new LineFormatter(), $handler, LogLevel::INFO, null, 'app', 'local');

        // Act
        $logger->info('first', ['a' => 1]);
        $logger->info("../../etc/passwd\n second");
        $files = glob($this->directory . '/logs/*') ?: [];

        // Assert
        self::assertCount(1, $files);
        self::assertSame('trunk-2026-09-20.log', basename($files[0]));
        $lines = file($files[0], FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(2, $lines);
        self::assertStringContainsString('INFO first {"service":"app"', $lines[0]);
        self::assertSame('0640', substr(\sprintf('%o', fileperms($files[0])), -4), 'log files are not world-readable');
    }

    public function test_the_stream_handler_writes_to_a_stream_and_reports_failures(): void
    {
        // Arrange
        $memory = fopen('php://memory', 'w+');
        self::assertIsResource($memory);
        $logger = new StructuredLogger(new JsonFormatter(), new StreamHandler($memory), LogLevel::INFO);

        // Act
        $logger->info('to memory');
        rewind($memory);
        $written = stream_get_contents($memory);

        // Assert
        self::assertIsString($written);
        self::assertSame('to memory', $this->decode($written)['message']);
        $this->expectException(RuntimeException::class);
        new StreamHandler('file:///nonexistent-dir-xyz/log')->write('x');
    }

    public function test_the_factory_builds_a_logger_from_config_and_reports_every_bad_setting(): void
    {
        // Arrange
        $runtime = new Runtime(Environment::Production, false, $this->directory);
        $good = new Configuration(['logging' => ['level' => 'warning', 'channel' => 'null', 'format' => 'json', 'service' => 'shop', 'redact' => ['ssn']]]);
        $bad = new Configuration(['logging' => ['level' => 'loud', 'channel' => 'syslog', 'format' => 'xml', 'redact' => [123]]]);

        // Act
        $logger = new LoggerFactory()->create($good, $runtime, new ContextHolder());
        $problems = LoggerFactory::problems($bad);

        // Assert
        self::assertFalse($logger->isEnabled('info'));
        self::assertTrue($logger->isEnabled('error'));
        self::assertCount(4, $problems);
        self::assertSame([], LoggerFactory::problems(new Configuration([])), 'no config at all is fine: safe defaults');
        $this->expectException(\Trunk\Foundation\Exception\ConfigurationException::class);
        new LoggerFactory()->create($bad, $runtime, new ContextHolder());
    }

    public function test_the_context_holder_is_cleared_by_reset(): void
    {
        // Arrange
        $holder = new ContextHolder();
        $holder->set(new RequestContext('req_1', 't', 's'));

        // Act
        $holder->reset();

        // Assert
        self::assertNull($holder->get());
    }

    /**
     * @param list<string> $redact
     *
     * @return array{StructuredLogger, object{lines: list<string>}}
     */
    private function logger(string $level = LogLevel::DEBUG, ?ContextHolder $holder = null, array $redact = []): array
    {
        $sink = new class implements LogHandler {
            /** @var list<string> */
            public array $lines = [];

            public function write(string $line): void
            {
                $this->lines[] = $line;
            }
        };

        return [new StructuredLogger(new JsonFormatter(), $sink, $level, $holder, 'workflow-api', 'production', new ContextNormalizer(new Redactor($redact)), static fn(): DateTimeImmutable => new DateTimeImmutable('2026-09-19 08:30:10.123 UTC')), $sink];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $line): array
    {
        $data = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame("\n", substr($line, -1));
        self::assertSame(1, substr_count($line, "\n"), 'exactly one line per record');

        return array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
