<?php

declare(strict_types=1);

namespace Trunk\Error;

/**
 * What the handler knows about where the error happened. `kind` is `http`, `cli` or `job`.
 */
final readonly class ErrorContext
{
    public function __construct(
        public string $kind = 'http',
        public ?string $requestId = null,
        public ?string $traceId = null,
        public bool $debug = false,
    ) {}
}
