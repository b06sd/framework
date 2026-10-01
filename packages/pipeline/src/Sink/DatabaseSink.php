<?php

declare(strict_types=1);

namespace Trunk\Pipeline\Sink;

use Trunk\Database\Connection\Connection;
use Trunk\Pipeline\Sink;

/**
 * Writes a chunk as one batched INSERT (Connection\QueryBuilder::insert() already chunks by the
 * database's own bound-parameter limit and wraps more than one statement in a transaction, so a
 * large chunk size here is still safe).
 *
 * @api
 */
final readonly class DatabaseSink implements Sink
{
    public function __construct(private Connection $connection, private string $table) {}

    public function write(array $records): void
    {
        $this->connection->table($this->table)->insert($records);
    }
}
