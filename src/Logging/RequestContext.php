<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * Correlation data for one unit of work: an HTTP request (`http`), a console command (`cli`) or a
 * queued job (`job`). Every log record written while it is current carries the ids.
 *
 * @api
 */
final readonly class RequestContext
{
    public function __construct(
        public string $requestId,
        public string $traceId,
        public string $spanId,
        public string $kind = 'http',
        /** For a job: the id of the request that queued it (validated, log-safe). */
        public ?string $originRequestId = null,
    ) {}
}
