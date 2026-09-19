<?php

declare(strict_types=1);

namespace Trunk\Queue\Driver;

use Trunk\Database\Connection\ConnectionManager;

/**
 * Builds the database driver on the configured connection. The default connection is the same
 * instance the application uses, so a job dispatched inside a transaction shares that transaction.
 */
final readonly class DatabaseDriverFactory
{
    public function create(ConnectionManager $connections, string $connection, string $table, string $failedTable): DatabaseDriver
    {
        return new DatabaseDriver($connections->connection($connection), $table, $failedTable);
    }
}
