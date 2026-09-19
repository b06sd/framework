<?php

declare(strict_types=1);

namespace Trunk\Http\Error;

/**
 * @api
 */
final readonly class RenderedError
{
    /**
     * @param array<string, string> $headers extra response headers (for example a Content-Security-Policy)
     */
    public function __construct(
        public string $contentType,
        public string $body,
        public array $headers = [],
    ) {}
}
