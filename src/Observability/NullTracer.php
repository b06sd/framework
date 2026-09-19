<?php

declare(strict_types=1);

namespace Trunk\Observability;

use Throwable;

final readonly class NullTracer implements Tracer
{
    public function startSpan(string $name, array $attributes = []): Span
    {
        return new class implements Span {
            public function setAttribute(string $key, string|int|float|bool $value): void {}

            public function recordException(Throwable $error): void {}

            public function end(): void {}

            public function traceId(): string
            {
                return str_repeat('0', 32);
            }

            public function spanId(): string
            {
                return str_repeat('0', 16);
            }

            public function traceparent(): string
            {
                return '00-' . str_repeat('0', 32) . '-' . str_repeat('0', 16) . '-00';
            }
        };
    }
}
