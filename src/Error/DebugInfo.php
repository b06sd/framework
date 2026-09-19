<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * Development-only detail about an exception. Built only when debug is on; contains paths and
 * source lines, so it must never reach a production response.
 *
 * @api
 */
final readonly class DebugInfo
{
    /**
     * @param list<array{line: int, code: string, current: bool}> $snippet
     * @param list<string>                                        $trace   frames without arguments
     * @param list<string>                                        $previous "Class: message" of earlier exceptions
     */
    public function __construct(
        public string $class,
        public string $message,
        public ?string $hint,
        public string $file,
        public int $line,
        public array $snippet,
        public array $trace,
        public array $previous,
    ) {}
}
