<?php

declare(strict_types=1);

namespace Trunk\Database\Connection;

use Trunk\Database\Driver\Driver;
use Trunk\Database\Driver\MySqlDriver;
use Trunk\Database\Driver\PostgresDriver;
use Trunk\Database\Driver\SqliteDriver;
use Trunk\Database\Exception\ConnectionException;

final readonly class ConnectionFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function make(string $name, array $config, ?QueryLog $log = null): Connection
    {
        $driver = $config['driver'] ?? null;

        return new Connection($name, $this->driver(\is_string($driver) ? $driver : '', $name), $config, $log);
    }

    private function driver(string $driver, string $connection): Driver
    {
        return match ($driver) {
            'sqlite' => new SqliteDriver(),
            'mysql' => new MySqlDriver(),
            'pgsql' => new PostgresDriver(),
            default => throw new ConnectionException(\sprintf('Connection "%s" has an unsupported driver. Set "driver" to sqlite, mysql or pgsql (DB_CONNECTION in .env).', $connection)),
        };
    }
}
