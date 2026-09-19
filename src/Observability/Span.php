<?php

declare(strict_types=1);

namespace Trunk\Observability;

use Throwable;

/**
 * One timed operation inside a trace. The methods mirror OpenTelemetry's span API so an
 * OpenTelemetry-backed Tracer can be a thin adapter.
 *
 * @api
 */
interface Span
{
    public function setAttribute(string $key, string|int|float|bool $value): void;

    public function recordException(Throwable $error): void;

    public function end(): void;

    public function traceId(): string;

    public function spanId(): string;

    /** The W3C `traceparent` header value to send on an outgoing call, so the trace continues downstream. */
    public function traceparent(): string;
}
