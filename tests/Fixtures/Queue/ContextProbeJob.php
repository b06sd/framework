<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use Trunk\Logging\ContextHolder;
use Trunk\Queue\Job\Job;

final readonly class ContextProbeJob implements Job
{
    public function handle(ContextHolder $holder, Recorder $recorder): void
    {
        $context = $holder->get();
        $recorder->events[] = $context === null ? 'no-context' : $context->kind . ':' . $context->requestId;
        $recorder->events[] = $context === null ? 'none' : 'trace:' . $context->traceId . ' origin:' . ($context->originRequestId ?? '-');
    }
}
