<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

use Trunk\Database\Exception\SchemaException;

final class MySqlSchemaGrammar extends SchemaGrammar
{
    public function compileTableExists(): string
    {
        return 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?';
    }

    public function compileColumnExists(): string
    {
        return 'SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?';
    }

    public function compileListTables(): string
    {
        return "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'";
    }

    public function compileDropAll(array $tables): array
    {
        return [
            'SET FOREIGN_KEY_CHECKS = 0',
            ...array_map(fn(string $table): string => 'DROP TABLE IF EXISTS ' . $this->quote($table), $tables),
            'SET FOREIGN_KEY_CHECKS = 1',
        ];
    }
    protected function quote(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    protected function idDefinition(): string
    {
        return 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
    }

    protected function typeFor(Column $column): string
    {
        $unsigned = $column->unsigned ? ' UNSIGNED' : '';

        return match ($column->type) {
            'string' => 'VARCHAR(' . ($column->parameters[0] ?? 255) . ')',
            'text' => 'TEXT',
            'json' => 'JSON',
            'integer' => 'INT' . $unsigned,
            'bigInteger' => 'BIGINT' . $unsigned,
            'boolean' => 'TINYINT(1)',
            'decimal' => 'DECIMAL(' . ($column->parameters[0] ?? 10) . ', ' . ($column->parameters[1] ?? 2) . ')' . $unsigned,
            'float' => 'DOUBLE',
            'date' => 'DATE',
            'dateTime' => 'DATETIME',
            'timestamp' => 'TIMESTAMP',
            'uuid' => 'CHAR(36)',
            'binary' => 'BLOB',
            default => throw new SchemaException(\sprintf('Unknown column type "%s".', $column->type)),
        };
    }

    protected function literal(string $value): string
    {
        // MySQL treats backslash as an escape character inside string literals.
        return "'" . str_replace(["\\", "'"], ["\\\\", "''"], $value) . "'";
    }

    protected function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->quote($name) . ' ON ' . $this->quote($table);
    }
}
