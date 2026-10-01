<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Logging;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Trunk\Container\ContainerBuilder;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Logging\JsonFormatter;
use Trunk\Logging\LineFormatter;
use Trunk\Logging\LoggerFactory;
use Trunk\Logging\LogRecord;
use Trunk\Logging\Logs;
use Trunk\Logging\StructuredLogger;
use Trunk\Tests\Support\ContainerModes;
use Trunk\Tests\Support\RecordingLogHandler;

final class LogsTest extends TestCase
{
    public function test_the_most_specific_prefix_decides_and_anything_unlisted_uses_the_default(): void
    {
        // Arrange
        [$root, $sink] = $this->root(LogLevel::DEBUG);
        $logs = new Logs($root, LogLevel::WARNING, ['App' => LogLevel::ERROR, 'App\Billing' => LogLevel::DEBUG, 'payments' => LogLevel::INFO]);

        // Act: each category logs one debug and one info record
        foreach (['App\Billing\Invoices', 'App\Billing', 'App\BillingReport', 'app\billing\Refunds', 'payments.stripe', 'App\Users', 'Other\Thing'] as $category) {
            $logs->for($category)->debug('d');
            $logs->for($category)->info('i');
            $logs->for($category)->error('e');
        }

        // Assert: which (category, level) pairs got through
        $seen = array_map(static fn(array $r): string => $r['category'] . ':' . $r['level'], $sink->records());
        self::assertSame([
            'App\Billing\Invoices:DEBUG', 'App\Billing\Invoices:INFO', 'App\Billing\Invoices:ERROR',
            'App\Billing:DEBUG', 'App\Billing:INFO', 'App\Billing:ERROR',
            'App\BillingReport:ERROR',
            'app\billing\Refunds:DEBUG', 'app\billing\Refunds:INFO', 'app\billing\Refunds:ERROR',
            'payments.stripe:INFO', 'payments.stripe:ERROR',
            'App\Users:ERROR',
            'Other\Thing:ERROR',
        ], $seen, 'App\Billing does not cover App\BillingReport (a prefix only counts at a \ or . boundary); names compare without case');
    }

    public function test_a_category_can_be_more_verbose_than_the_default_with_trunks_logger(): void
    {
        // Arrange: the root logger itself is at warning
        [$root, $sink] = $this->root(LogLevel::WARNING);
        $logs = new Logs($root, LogLevel::WARNING, ['App\Billing' => LogLevel::DEBUG]);

        // Act
        $root->debug('root debug');
        $logs->for('App\Billing')->debug('billing debug');

        // Assert
        self::assertSame(['billing debug'], array_column($sink->records(), 'message'));
    }

    public function test_the_category_on_a_record_cannot_be_spoofed_from_the_context(): void
    {
        // Arrange
        [$root, $sink] = $this->root(LogLevel::DEBUG);
        $logs = new Logs($root, LogLevel::DEBUG);

        // Act
        $logs->for('App\Billing')->info('m', ['category' => "admin\nINFO forged"]);
        $root->info('m', ['category' => 'App\Billing']);

        // Assert
        [$first, $second] = $sink->records();
        self::assertSame('App\Billing', $first['category']);
        self::assertSame("admin\\nINFO forged", $first['category_'], 'kept, renamed, and sanitised like any other context value');
        self::assertArrayNotHasKey('category', $second, 'the root logger has no category');
    }

    public function test_bad_names_and_levels_are_refused(): void
    {
        // Arrange
        [$root] = $this->root(LogLevel::DEBUG);
        $logs = new Logs($root);

        // Act & Assert
        foreach (['', "App\nBilling", 'has space', str_repeat('a', 201), '\App'] as $name) {
            try {
                $logs->for($name);
                self::fail(\sprintf('"%s" should be refused', $name));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new Logs($root, LogLevel::INFO, ['App' => 'loud']);
    }

    public function test_a_logger_is_kept_per_category(): void
    {
        // Arrange
        [$root] = $this->root(LogLevel::DEBUG);
        $logs = new Logs($root);

        // Act & Assert
        self::assertSame($logs->for('App\Billing'), $logs->for('App\Billing'));
        self::assertNotSame($logs->for('App\Billing'), $logs->for('App\Users'));
    }

    public function test_the_build_reports_a_bad_levels_map_with_the_fix(): void
    {
        // Arrange
        $cases = [
            'not a map' => ['levels' => ['debug']],
            'bad name' => ['levels' => ['App Billing' => 'debug']],
            'bad level' => ['levels' => ['App\Billing' => 'loud']],
        ];

        foreach ($cases as $case => $logging) {
            // Act
            $problems = LoggerFactory::problems(new Configuration(['logging' => $logging]));

            // Assert
            self::assertCount(1, $problems, $case);
            self::assertStringContainsString('logging.levels', $problems[0], $case);
        }

        self::assertSame([], LoggerFactory::problems(new Configuration(['logging' => ['levels' => ['App\Billing' => 'debug', 'payments.stripe' => 'error']]])));
    }

    public function test_the_line_format_shows_the_category_before_the_message(): void
    {
        // Arrange
        $record = new LogRecord(new DateTimeImmutable('2026-09-19 08:30:10.123 UTC'), LogLevel::INFO, 'Invoice sent', ['service' => 'app', 'category' => 'App\Billing', 'invoice' => 7]);

        // Act
        $line = new LineFormatter()->format($record);

        // Assert
        self::assertSame("2026-09-19T08:30:10.123Z INFO [App\\Billing] Invoice sent {\"service\":\"app\",\"invoice\":7}\n", $line);
    }

    public function test_logs_is_wired_over_the_bound_logger_in_both_container_modes(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $builder) => new LoggingModule()->register($builder),
            ['logging' => ['channel' => 'null', 'level' => 'warning', 'levels' => ['App\Billing' => 'debug']]],
            [Logs::class],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $logs = $container->get(Logs::class);

            // Assert
            self::assertInstanceOf(Logs::class, $logs, $mode);
            self::assertInstanceOf(StructuredLogger::class, $logs->for('App\Billing'), $mode);
            self::assertSame($logs, $container->get(Logs::class), $mode);
        }
    }

    /**
     * @return array{StructuredLogger, RecordingLogHandler}
     */
    private function root(string $level): array
    {
        $sink = new RecordingLogHandler();

        return [new StructuredLogger(new JsonFormatter(), $sink, $level), $sink];
    }
}
