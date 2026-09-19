<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;
use Trunk\Lifecycle\LifecycleAware;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\TraceParent;
use Trunk\Observability\Span;
use Trunk\Observability\Tracer;

/**
 * Writes one structured record per finished span through PSR-3 (message `span`, with traceId,
 * spanId, parentSpanId, name, durationMs, status and attributes), so any log platform can build
 * trace views without a tracing backend. Spans nest: a span started while another is open becomes
 * its child. Exceptions are recorded as class only, never the message.
 */
final class LogTracer implements Tracer, LifecycleAware
{
    /** @var list<array{traceId: string, spanId: string}> */
    private array $open = [];

    /**
     * @param (Closure(): int)|null $clock nanoseconds
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?ContextHolder $context = null,
        private readonly ?Closure $clock = null,
    ) {}

    public function startSpan(string $name, array $attributes = []): Span
    {
        $parent = $this->open[\count($this->open) - 1] ?? null;
        $current = $this->context?->get();
        $traceId = $parent['traceId'] ?? $current->traceId ?? TraceParent::newTraceId();
        $parentSpanId = $parent['spanId'] ?? $current->spanId ?? null;
        $spanId = TraceParent::newSpanId();
        $this->open[] = ['traceId' => $traceId, 'spanId' => $spanId];
        $start = $this->now();

        return new class ($this, $this->logger, $name, $attributes, $traceId, $spanId, $parentSpanId, $start, $this->clock) implements Span {
            private bool $ended = false;

            private string $status = 'ok';

            /**
             * @param array<string, string|int|float|bool> $attributes
             * @param (Closure(): int)|null                $clock
             */
            public function __construct(private readonly LogTracer $tracer, private readonly LoggerInterface $logger, private readonly string $name, private array $attributes, private readonly string $traceId, private readonly string $spanId, private readonly ?string $parentSpanId, private readonly int $start, private readonly ?Closure $clock) {}

            public function setAttribute(string $key, string|int|float|bool $value): void
            {
                $this->attributes[$key] = $value;
            }

            public function recordException(Throwable $error): void
            {
                $this->status = 'error';
                $this->attributes['exception'] = $error::class;
            }

            public function end(): void
            {
                if ($this->ended) {
                    return;
                }

                $this->ended = true;
                $this->tracer->close($this->spanId);
                $now = $this->clock !== null ? ($this->clock)() : (int) hrtime(true);

                try {
                    $this->logger->info('span', ['name' => $this->name, 'traceId' => $this->traceId, 'spanId' => $this->spanId, 'parentSpanId' => $this->parentSpanId, 'durationMs' => round(($now - $this->start) / 1_000_000, 3), 'status' => $this->status, 'attributes' => $this->attributes]);
                } catch (Throwable) {
                    // Tracing must never break the traced work.
                }
            }

            public function traceId(): string
            {
                return $this->traceId;
            }

            public function spanId(): string
            {
                return $this->spanId;
            }

            public function traceparent(): string
            {
                return '00-' . $this->traceId . '-' . $this->spanId . '-01';
            }
        };
    }

    /** @internal called by a span when it ends */
    public function close(string $spanId): void
    {
        $this->open = array_values(array_filter($this->open, static fn(array $s): bool => $s['spanId'] !== $spanId));
    }

    public function reset(): void
    {
        $this->open = [];
    }

    private function now(): int
    {
        return $this->clock !== null ? ($this->clock)() : (int) hrtime(true);
    }
}
