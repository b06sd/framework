<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Telemetry;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Observability\NullMetrics;
use Trunk\Observability\NullTracer;
use Trunk\Telemetry\InMemoryMetrics;
use Trunk\Telemetry\LogTracer;
use Trunk\Telemetry\PrometheusFormatter;
use Trunk\Tests\Support\RecordingLogger;

final class TelemetryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function invalid(): iterable
    {
        yield 'uppercase name' => ['Requests', []];
        yield 'name with space' => ['my metric', []];
        yield 'name with quote' => ['x"y', []];
        yield 'empty name' => ['', []];
        yield 'label with dash' => ['requests', ['bad-label' => 'x']];
        yield 'label uppercase' => ['requests', ['Route' => 'x']];
        yield 'too many labels' => ['requests', ['a' => '1', 'b' => '1', 'c' => '1', 'd' => '1', 'e' => '1', 'f' => '1']];
    }

    /**
     * @param array<string, string> $labels
     */
    #[DataProvider('invalid')]
    public function test_metric_and_label_names_are_validated(string $name, array $labels): void
    {
        // Arrange
        $metrics = new InMemoryMetrics();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $metrics->counter($name, $labels);
    }

    public function test_series_are_capped_so_a_runaway_label_cannot_exhaust_memory(): void
    {
        // Arrange
        $metrics = new InMemoryMetrics(maxSeries: 50);

        // Act
        for ($i = 0; $i < 5000; ++$i) {
            $metrics->counter('requests_total', ['user' => 'user-' . $i]);
        }

        // Assert
        self::assertCount(50, $metrics->snapshot());
        self::assertSame(4950, $metrics->dropped());
        self::assertStringContainsString('trunk_metrics_dropped_total 4950', new PrometheusFormatter()->format($metrics));
    }

    public function test_counters_gauges_and_histograms_export_in_prometheus_format_with_escaped_labels(): void
    {
        // Arrange
        $metrics = new InMemoryMetrics();
        $metrics->counter('jobs_total', ['outcome' => 'succeeded']);
        $metrics->counter('jobs_total', ['outcome' => 'succeeded'], 2);
        $metrics->gauge('memory_mb', 41.5);
        $metrics->observe('duration_seconds', 0.03, ['route' => 'a"b\\c' . "\n" . 'd']);
        $metrics->observe('duration_seconds', 2.0, ['route' => 'a"b\\c' . "\n" . 'd']);

        // Act
        $text = new PrometheusFormatter()->format($metrics);

        // Assert
        self::assertStringContainsString("# TYPE jobs_total counter\njobs_total{outcome=\"succeeded\"} 3", $text);
        self::assertStringContainsString('memory_mb 41.5', $text);
        self::assertStringContainsString('duration_seconds_bucket{route="a\"b\\\\c?d",le="0.05"} 1', $text);
        self::assertStringContainsString('duration_seconds_bucket{route="a\"b\\\\c?d",le="2.5"} 2', $text);
        self::assertStringContainsString('duration_seconds_bucket{route="a\"b\\\\c?d",le="+Inf"} 2', $text);
        self::assertStringContainsString('duration_seconds_sum{route="a\"b\\\\c?d"} 2.03', $text);
        self::assertStringContainsString('duration_seconds_count{route="a\"b\\\\c?d"} 2', $text);
        self::assertStringNotContainsString("\n\n", $text);
    }

    public function test_a_metric_cannot_change_type_and_counters_cannot_decrease(): void
    {
        // Arrange
        $metrics = new InMemoryMetrics();
        $metrics->counter('x_total');
        $rejected = 0;

        // Act
        foreach ([static fn() => $metrics->gauge('x_total', 1.0), static fn() => $metrics->counter('x_total', by: -1)] as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(2, $rejected);
        new NullMetrics()->counter('anything goes here');
    }

    public function test_spans_nest_carry_ids_time_and_record_exceptions_by_class_only(): void
    {
        // Arrange
        $logger = new RecordingLogger();
        $ticks = [0, 1_000_000, 4_000_000, 10_000_000];
        $tracer = new LogTracer($logger, null, static function () use (&$ticks): int {
            return array_shift($ticks) ?? 0;
        });

        // Act
        $outer = $tracer->startSpan('outer', ['job' => 'A']);
        $inner = $tracer->startSpan('inner');
        $inner->recordException(new RuntimeException('password=hunter2'));
        $inner->end();
        $inner->end();
        $outer->end();

        // Assert
        self::assertCount(2, $logger->records, 'ending twice records once');
        self::assertSame('span', $logger->records[0][1]);
        self::assertSame(1, preg_match('/^00-' . $outer->traceId() . '-' . $outer->spanId() . '-01$/D', $outer->traceparent()));
        self::assertSame($outer->traceId(), $inner->traceId());
        self::assertNotSame($outer->spanId(), $inner->spanId());
        self::assertStringNotContainsString('hunter2', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_the_null_tracer_costs_nothing_and_a_failing_logger_never_breaks_traced_work(): void
    {
        // Arrange
        $null = new NullTracer()->startSpan('x');
        $broken = new LogTracer(new RecordingLogger(throws: true))->startSpan('y');

        // Act
        $null->setAttribute('a', 1);
        $null->recordException(new RuntimeException('x'));
        $null->end();
        $broken->end();

        // Assert
        self::assertSame(str_repeat('0', 32), $null->traceId());
    }
}
