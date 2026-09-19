<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{string, string}> */
    public array $records = [];

    public function __construct(private readonly bool $throws = false) {}

    /**
     * @param array<array-key, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->throws) {
            throw new RuntimeException('logger is broken');
        }

        $this->records[] = [\is_string($level) ? $level : 'unknown', (string) $message];
    }
}
