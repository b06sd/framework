<?php

declare(strict_types=1);

namespace Trunk\Observability;

/**
 * Starts spans. The default is a no-op; `logging.tracing` switches on LogTracer, and an
 * OpenTelemetry package can provide its own implementation.
 *
 * @api
 */
interface Tracer
{
    /**
     * @param array<string, string|int|float|bool> $attributes low-cardinality facts (route name, job name), never secrets
     */
    public function startSpan(string $name, array $attributes = []): Span;
}
