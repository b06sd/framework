<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * A human-readable line for local development: `2026-09-19T08:30:10.123Z ERROR message {context}`,
 * or `... ERROR [App\Billing] message {context}` for a record with a category.
 */
final readonly class LineFormatter implements LogFormatter
{
    public function format(LogRecord $record): string
    {
        $fields = $record->context;
        $category = \is_string($fields['category'] ?? null) ? '[' . $fields['category'] . '] ' : '';
        unset($fields['category']);
        $context = $fields === [] ? '' : ' ' . json_encode($fields, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return \sprintf("%s %s %s%s%s\n", $record->time->format('Y-m-d\TH:i:s.v\Z'), strtoupper($record->level), $category, $record->message, $context);
    }
}
