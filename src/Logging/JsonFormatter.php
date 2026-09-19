<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * One JSON object per line, ready for ELK, OpenSearch, Grafana Loki, Datadog, CloudWatch and the like.
 */
final readonly class JsonFormatter implements LogFormatter
{
    public function format(LogRecord $record): string
    {
        $data = ['timestamp' => $record->time->format('Y-m-d\TH:i:s.v\Z'), 'level' => strtoupper($record->level), 'message' => $record->message, ...$record->context];

        return json_encode($data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
    }
}
