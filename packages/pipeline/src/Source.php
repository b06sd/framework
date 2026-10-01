<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

/**
 * Where a pipeline's records come from, read a chunk at a time so a dataset larger than memory
 * never has to fit in memory at once. Returning fewer than $limit records (including none) tells
 * the runner the source is exhausted, the same convention keyset pagination already uses — correct
 * for a source that is not being concurrently written to during the run.
 *
 * @api
 */
interface Source
{
    /**
     * @return iterable<mixed>
     */
    public function read(int $offset, int $limit): iterable;
}
