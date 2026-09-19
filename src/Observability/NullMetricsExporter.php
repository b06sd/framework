<?php

declare(strict_types=1);

namespace Trunk\Observability;

/**
 * The default: there is nothing to export.
 */
final readonly class NullMetricsExporter implements MetricsExporter
{
    public function enabled(): bool
    {
        return false;
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function export(): string
    {
        return '';
    }
}
