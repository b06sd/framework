<?php

declare(strict_types=1);

namespace Trunk\Observability;

/**
 * Counters, gauges and histograms. Inject it where you count things; there is no static access.
 * Names and label names are fixed identifiers; label VALUES must have a small, bounded set of
 * possibilities (a route name, a status class, an outcome), never a user id, path or query, or the
 * series count explodes. Implementations enforce a hard series cap.
 *
 * @api
 */
interface Metrics
{
    /**
     * @param array<string, string> $labels
     */
    public function counter(string $name, array $labels = [], float $by = 1.0): void;

    /**
     * @param array<string, string> $labels
     */
    public function gauge(string $name, float $value, array $labels = []): void;

    /**
     * Records one observation in a histogram (durations in seconds, sizes in bytes).
     *
     * @param array<string, string> $labels
     */
    public function observe(string $name, float $value, array $labels = []): void;
}
