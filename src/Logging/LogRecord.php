<?php

declare(strict_types=1);

namespace Trunk\Logging;

use DateTimeImmutable;

/**
 * One log entry after normalisation: everything in `context` is already redacted, sanitised and
 * JSON-safe.
 */
final readonly class LogRecord
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public DateTimeImmutable $time,
        public string $level,
        public string $message,
        public array $context,
    ) {}
}
