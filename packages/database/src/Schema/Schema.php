<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

use Closure;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Query\Identifier;

/**
 * Creates and changes tables. Inject it (or use it inside a migration).
 *
 * @api
 */
final readonly class Schema
{
    public function __construct(private Connection $connection) {}

    /**
     * @param Closure(Blueprint): void $define
     */
    public function create(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->run($this->connection->driver()->schemaGrammar()->compileCreate($blueprint));
    }

    /**
     * Changes an existing table (add columns and indexes, drop columns and indexes).
     *
     * @param Closure(Blueprint): void $define
     */
    public function table(string $table, Closure $define): void
    {
        $blueprint = new Blueprint($table);
        $define($blueprint);
        $this->run($this->connection->driver()->schemaGrammar()->compileAlter($blueprint));
    }

    public function drop(string $table): void
    {
        $this->connection->execute($this->connection->driver()->schemaGrammar()->compileDrop($table, false));
    }

    public function dropIfExists(string $table): void
    {
        $this->connection->execute($this->connection->driver()->schemaGrammar()->compileDrop($table, true));
    }

    public function rename(string $from, string $to): void
    {
        $this->connection->execute($this->connection->driver()->schemaGrammar()->compileRename($from, $to));
    }

    public function hasTable(string $table): bool
    {
        Identifier::assertSimple($table);

        return $this->connection->selectOne($this->connection->driver()->schemaGrammar()->compileTableExists(), [$table]) !== null;
    }

    public function hasColumn(string $table, string $column): bool
    {
        Identifier::assertSimple($table);
        Identifier::assertSimple($column);

        return $this->connection->selectOne($this->connection->driver()->schemaGrammar()->compileColumnExists(), [$table, $column]) !== null;
    }

    /**
     * Drops every table in the current database. Destructive: used by `migrate:fresh`.
     */
    public function dropAllTables(): void
    {
        $grammar = $this->connection->driver()->schemaGrammar();
        $tables = array_map(static function (array $row): string {
            $name = reset($row);

            return \is_string($name) ? $name : '';
        }, $this->connection->select($grammar->compileListTables()));

        // SQLite cannot switch foreign keys off inside a transaction, so this runs outside one.
        $sqlite = $this->connection->driver()->name() === 'sqlite';
        $sqlite && $this->connection->execute('PRAGMA foreign_keys = OFF');

        try {
            $this->run($grammar->compileDropAll($tables));
        } finally {
            $sqlite && $this->connection->execute('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * @param list<string> $statements
     */
    private function run(array $statements): void
    {
        foreach ($statements as $statement) {
            $this->connection->execute($statement);
        }
    }
}
