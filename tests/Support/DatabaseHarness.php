<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Connection\QueryLog;

final class DatabaseHarness
{
    /**
     * A real in-memory SQLite connection.
     */
    public function sqlite(?QueryLog $log = null): Connection
    {
        return new ConnectionFactory()->make('test', ['driver' => 'sqlite', 'database' => ':memory:'], $log);
    }

    /**
     * A connection that is never opened, used to inspect the SQL a driver's grammar produces.
     */
    public function grammarOnly(string $driver): Connection
    {
        return new ConnectionFactory()->make('golden', ['driver' => $driver, 'database' => 'unused', 'host' => 'localhost']);
    }

    public function seededUsers(Connection $connection): Connection
    {
        $connection->execute('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(100) NOT NULL, email VARCHAR(150) UNIQUE, age INTEGER, active BOOLEAN NOT NULL DEFAULT 1, score REAL)');
        $connection->table('users')->insert([
            ['name' => 'Ada', 'email' => 'ada@example.com', 'age' => 36, 'active' => true, 'score' => 9.5],
            ['name' => 'Grace', 'email' => 'grace@example.com', 'age' => 45, 'active' => true, 'score' => 8.25],
            ['name' => 'Linus', 'email' => 'linus@example.com', 'age' => 17, 'active' => false, 'score' => null],
        ]);

        return $connection;
    }
}
