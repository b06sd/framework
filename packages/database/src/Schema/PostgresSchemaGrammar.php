<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

use Trunk\Database\Exception\SchemaException;

final class PostgresSchemaGrammar extends SchemaGrammar
{
    public function compileTableExists(): string
    {
        return 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename = ?';
    }

    public function compileColumnExists(): string
    {
        return 'SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?';
    }

    public function compileListTables(): string
    {
        return 'SELECT tablename FROM pg_tables WHERE schemaname = current_schema()';
    }

    public function compileDropAll(array $tables): array
    {
        return $tables === [] ? [] : ['DROP TABLE IF EXISTS ' . implode(', ', array_map($this->quote(...), $tables)) . ' CASCADE'];
    }
    protected function quote(string $identifier): string
    {
        return '"' . $identifier . '"';
    }

    protected function idDefinition(): string
    {
        return 'BIGSERIAL PRIMARY KEY';
    }

    protected function boolLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    protected function typeFor(Column $column): string
    {
        return match ($column->type) {
            'string' => 'VARCHAR(' . ($column->parameters[0] ?? 255) . ')',
            'text' => 'TEXT',
            'json' => 'JSON',
            'integer' => 'INTEGER',
            'bigInteger' => 'BIGINT',
            'boolean' => 'BOOLEAN',
            'decimal' => 'NUMERIC(' . ($column->parameters[0] ?? 10) . ', ' . ($column->parameters[1] ?? 2) . ')',
            'float' => 'DOUBLE PRECISION',
            'date' => 'DATE',
            'dateTime', 'timestamp' => 'TIMESTAMP(0) WITHOUT TIME ZONE',
            'uuid' => 'UUID',
            'binary' => 'BYTEA',
            default => throw new SchemaException(\sprintf('Unknown column type "%s".', $column->type)),
        };
    }

    protected function compileDropIndex(string $table, string $name): string
    {
        return 'DROP INDEX ' . $this->quote($name);
    }
}
