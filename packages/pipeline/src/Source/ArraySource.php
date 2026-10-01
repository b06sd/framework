<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Source;

use Trunk\Pipeline\Source;

/**
 * A fixed, in-memory list of records — for tests, and for a dataset small enough to already be an
 * array. Not for anything large: the whole list is held in memory regardless of chunk size.
 *
 * @api
 */
final readonly class ArraySource implements Source
{
    /**
     * @param list<mixed> $records
     */
    public function __construct(private array $records) {}

    public function read(int $offset, int $limit): iterable
    {
        yield from \array_slice($this->records, $offset, $limit);
    }
}
