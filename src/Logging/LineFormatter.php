<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * A human-readable line for local development: `2026-09-19T08:30:10.123Z ERROR message {context}`.
 */
final readonly class LineFormatter implements LogFormatter
{
    public function format(LogRecord $record): string
    {
        $context = $record->context === [] ? '' : ' ' . json_encode($record->context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \sprintf("%s %s %s%s\n", $record->time->format('Y-m-d\TH:i:s.v\Z'), strtoupper($record->level), $record->message, $context);
    }
}
