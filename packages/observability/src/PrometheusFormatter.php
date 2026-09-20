<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

/**
 * The Prometheus text exposition format (also read by Grafana Agent, Datadog and OpenTelemetry
 * collectors). Label values are escaped, so metric content cannot break the format.
 */
final class PrometheusFormatter
{
    public function format(InMemoryMetrics $metrics): string
    {
        $lines = [];
        $typed = [];

        foreach ($metrics->snapshot() as $series) {
            if (!isset($typed[$series->name])) {
                $typed[$series->name] = true;
                $lines[] = '# TYPE ' . $series->name . ' ' . $series->type;
            }

            $labels = $series->labels;

            if ($series->type === 'histogram') {
                foreach ($series->buckets as $index => $count) {
                    $lines[] = $series->name . '_bucket' . $this->labels([...$labels, 'le' => (string) InMemoryMetrics::BUCKETS[$index]]) . ' ' . $count;
                }

                $lines[] = $series->name . '_bucket' . $this->labels([...$labels, 'le' => '+Inf']) . ' ' . $series->count;
                $lines[] = $series->name . '_sum' . $this->labels($labels) . ' ' . $this->number($series->sum);
                $lines[] = $series->name . '_count' . $this->labels($labels) . ' ' . $series->count;

                continue;
            }

            $lines[] = $series->name . $this->labels($labels) . ' ' . $this->number($series->value);
        }

        if ($metrics->dropped() > 0) {
            $lines[] = '# TYPE trunk_metrics_dropped_total counter';
            $lines[] = 'trunk_metrics_dropped_total ' . $metrics->dropped();
        }

        return $lines === [] ? "# No samples yet. Metrics are kept per PHP process, so a server that starts a new process for every request (php -S, php-fpm) always starts empty.\n" : implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private function labels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $pairs = [];

        foreach ($labels as $key => $value) {
            $pairs[] = $key . '="' . strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']) . '"';
        }

        return '{' . implode(',', $pairs) . '}';
    }

    private function number(float $value): string
    {
        return $value === floor($value) && abs($value) < 1e15 ? (string) (int) $value : rtrim(rtrim(number_format($value, 9, '.', ''), '0'), '.');
    }
}
