<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

/**
 * Where a pipeline's records end up. One call per chunk, not per record, so a Sink can write in
 * batches (one INSERT, one API call) instead of one round trip per row.
 *
 * @api
 */
interface Sink
{
    /**
     * @param list<mixed> $records
     */
    public function write(array $records): void;
}
