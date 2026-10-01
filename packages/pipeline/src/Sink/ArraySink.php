<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Sink;

use Trunk\Pipeline\Sink;

/**
 * Collects every record written to it, in memory — for tests, never for a real destination.
 *
 * @api
 */
final class ArraySink implements Sink
{
    /** @var list<mixed> */
    private array $records = [];

    public function write(array $records): void
    {
        array_push($this->records, ...$records);
    }

    /**
     * @return list<mixed>
     */
    public function all(): array
    {
        return $this->records;
    }
}
