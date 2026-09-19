<?php

declare(strict_types=1);

namespace Trunk\Observability;

/**
 * The default: metrics calls cost nothing and record nothing.
 */
final readonly class NullMetrics implements Metrics
{
    public function counter(string $name, array $labels = [], float $by = 1.0): void {}

    public function gauge(string $name, float $value, array $labels = []): void {}

    public function observe(string $name, float $value, array $labels = []): void {}
}
