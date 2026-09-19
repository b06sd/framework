<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

final class SqliteSchemaGrammar extends SchemaGrammar
{
    public function compileTableExists(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?";
    }

    public function compileColumnExists(): string
    {
        return 'SELECT name FROM pragma_table_info(?) WHERE name = ?';
    }

    public function compileListTables(): string
    {
        return "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite!_%' ESCAPE '!'";
    }

    public function compileDropAll(array $tables): array
    {
        return array_map(fn(string $table): string => 'DROP TABLE IF EXISTS ' . $this->quote($table), $tables);
    }
    protected function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    protected function idDefinition(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    protected function typeFor(Column $column): string
    {
        return match ($column->type) {
            'string' => 'VARCHAR(' . ($column->parameters[0] ?? 255) . ')',
            'text', 'json' => 'TEXT',
            'integer', 'bigInteger' => 'INTEGER',
            'boolean' => 'BOOLEAN',
            'decimal' => 'NUMERIC(' . ($column->parameters[0] ?? 10) . ', ' . ($column->parameters[1] ?? 2) . ')',
            'float' => 'REAL',
            'date' => 'DATE',
            'dateTime', 'timestamp' => 'DATETIME',
            'uuid' => 'VARCHAR(36)',
            'binary' => 'BLOB',
            default => throw new \Trunk\Database\Exception\SchemaException(\sprintf('Unknown column type "%s".', $column->type)),
        };
    }

    protected function supportsAddForeignKey(): bool
    {
        return false;
    }

    protected function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->quote($name);
    }
}
