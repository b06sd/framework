<?php

declare(strict_types=1);

namespace Trunk\Error;

use Throwable;

/**
 * The outcome of handling one exception: what to tell the client, what was logged, and (in debug
 * only) the developer detail. Renderers turn this into JSON, HTML or text.
 *
 * `exception` stays in-process for renderers that need it in development; production renderers
 * use only the safe fields.
 *
 * @api
 */
final readonly class ErrorReport
{
    /**
     * @param array<string, mixed> $details client-safe details (validation fields, ...)
     */
    public function __construct(
        public int $status,
        public string $code,
        public string $message,
        public ?string $requestId,
        public bool $public,
        public string $level,
        public ?string $internalCode,
        public array $details,
        public Throwable $exception,
        public ?DebugInfo $debug = null,
    ) {}
}
