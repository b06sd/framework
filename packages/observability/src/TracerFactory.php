<?php

declare(strict_types=1);

namespace Trunk\Telemetry;

use Psr\Log\LoggerInterface;
use Trunk\Foundation\Configuration;
use Trunk\Logging\ContextHolder;
use Trunk\Observability\NullTracer;
use Trunk\Observability\Tracer;

/**
 * `observability.tracing = true` records spans as structured log records; anything else is a no-op.
 */
final readonly class TracerFactory
{
    public function create(Configuration $configuration, LoggerInterface $logger, ContextHolder $context): Tracer
    {
        return $configuration->has('observability.tracing') && $configuration->get('observability.tracing') === true ? new LogTracer($logger, $context) : new NullTracer();
    }
}
