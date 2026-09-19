<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * The W3C `traceparent` header (`00-<32 hex trace id>-<16 hex span id>-<2 hex flags>`). A header
 * that does not match exactly, or carries an all-zero id, is ignored and a fresh trace is started.
 */
final class TraceParent
{
    /**
     * @return array{traceId: string, spanId: string}|null
     */
    public static function parse(?string $header): ?array
    {
        if ($header === null || preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/D', $header, $m) !== 1) {
            return null;
        }

        if (trim($m[1], '0') === '' || trim($m[2], '0') === '') {
            return null;
        }

        return ['traceId' => $m[1], 'spanId' => $m[2]];
    }

    public static function newTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function newSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
