<?php

declare(strict_types=1);

namespace Trunk\Observability;

/**
 * Renders recorded metrics for a scraper. The health capability's /metrics endpoint depends on this
 * interface only, so any metrics backend can provide it.
 *
 * @api
 */
interface MetricsExporter
{
    /** False when nothing is being recorded, in which case the endpoint does not exist. */
    public function enabled(): bool;

    public function contentType(): string;

    public function export(): string;
}
