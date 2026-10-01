<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

/**
 * One transform step. Return null to drop the record (a filter); anything else replaces it for the
 * next stage. A stage that throws fails the whole chunk — the queue's own retry handles it, the same
 * way any other job failure does; a bad record does not get skipped and continued past.
 *
 * @api
 */
interface Stage
{
    public function process(mixed $record): mixed;
}
