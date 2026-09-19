<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

use Trunk\Database\Exception\SchemaException;
use Trunk\Database\Query\Identifier;
use Trunk\Database\Query\Raw;

/**
 * Turns a Blueprint into DDL. Names are validated identifiers; default values (which cannot be
 * bound in DDL) are written as safely quoted literals, and only ever come from migration code.
 *
 * @internal an implementation detail, never a base class for application code
 */
abstract class SchemaGrammar
{
    /**
     * SQL (with `?` placeholders) returning a row when a table exists: bindings [table].
     */
    abstract public function compileTableExists(): string;

    /**
     * Bindings [table, column].
     */
    abstract public function compileColumnExists(): string;

    /**
     * SQL returning one column of table names in the current schema.
     */
    abstract public function compileListTables(): string;

    /**
     * @param list<string> $tables
     *
     * @return list<string>
     */
    abstract public function compileDropAll(array $tables): array;

    /**
     * @return list<string>
     */
    public function compileCreate(Blueprint $blueprint): array
    {
        $definitions = array_map($this->columnDefinition(...), $blueprint->columns());

        if ($blueprint->primaryKey() !== []) {
            $definitions[] = 'PRIMARY KEY (' . $this->columnList($blueprint->primaryKey()) . ')';
        }

        foreach ($blueprint->columns() as $column) {
            if ($column->referencesTable !== null) {
                $definitions[] = $this->foreignKey($column);
            }
        }

        if ($definitions === []) {
            throw new SchemaException(\sprintf('Table "%s" needs at least one column.', $blueprint->table));
        }

        $statements = ['CREATE TABLE ' . $this->quote($blueprint->table) . ' (' . implode(', ', $definitions) . ')'];

        foreach ($blueprint->indexes() as $index) {
            $statements[] = $this->compileIndex($blueprint->table, $index);
        }

        return $statements;
    }

    /**
     * @return list<string>
     */
    public function compileAlter(Blueprint $blueprint): array
    {
        $table = $this->quote($blueprint->table);
        $statements = [];

        foreach ($blueprint->columns() as $column) {
            $statements[] = 'ALTER TABLE ' . $table . ' ADD COLUMN ' . $this->columnDefinition($column);

            if ($column->referencesTable !== null) {
                if (!$this->supportsAddForeignKey()) {
                    throw new SchemaException('This database cannot add a foreign key to an existing table. Create the table with the foreign key instead.');
                }

                $statements[] = 'ALTER TABLE ' . $table . ' ADD CONSTRAINT ' . $this->quote($blueprint->table . '_' . $column->name . '_foreign') . ' ' . $this->foreignKey($column);
            }
        }

        foreach ($blueprint->indexes() as $index) {
            $statements[] = $this->compileIndex($blueprint->table, $index);
        }

        foreach ($blueprint->droppedIndexes() as $name) {
            $statements[] = $this->compileDropIndex($blueprint->table, $name);
        }

        foreach ($blueprint->droppedColumns() as $column) {
            $statements[] = 'ALTER TABLE ' . $table . ' DROP COLUMN ' . $this->quote($column);
        }

        return $statements;
    }

    public function compileDrop(string $table, bool $ifExists): string
    {
        Identifier::assertSimple($table);

        return 'DROP TABLE ' . ($ifExists ? 'IF EXISTS ' : '') . $this->quote($table);
    }

    public function compileRename(string $from, string $to): string
    {
        Identifier::assertSimple($from);
        Identifier::assertSimple($to);

        return 'ALTER TABLE ' . $this->quote($from) . ' RENAME TO ' . $this->quote($to);
    }
    abstract protected function quote(string $identifier): string;

    abstract protected function typeFor(Column $column): string;

    abstract protected function idDefinition(): string;

    abstract protected function compileDropIndex(string $table, string $name): string;

    protected function supportsAddForeignKey(): bool
    {
        return true;
    }

    protected function literal(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    protected function boolLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    protected function columnDefinition(Column $column): string
    {
        $name = $this->quote($column->name);

        if ($column->type === 'id') {
            return $name . ' ' . $this->idDefinition();
        }

        $sql = $name . ' ' . $this->typeFor($column) . ($column->nullable ? ' NULL' : ' NOT NULL');

        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($column->default);
        }

        return $sql . ($column->primary ? ' PRIMARY KEY' : '');
    }

    protected function defaultValue(string|int|float|bool|Raw|null $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            \is_bool($value) => $this->boolLiteral($value),
            \is_int($value) => (string) $value,
            \is_float($value) => is_finite($value) ? var_export($value, true) : throw new SchemaException('A default value must be a finite number.'),
            $value instanceof Raw => $value->sql,
            str_contains($value, "\0") => throw new SchemaException('A default value must not contain NUL bytes.'),
            default => $this->literal($value),
        };
    }

    /**
     * @param list<string> $columns
     */
    protected function columnList(array $columns): string
    {
        return implode(', ', array_map($this->quote(...), $columns));
    }

    /**
     * @param array{type: string, columns: list<string>, name: string} $index
     */
    protected function compileIndex(string $table, array $index): string
    {
        Identifier::assertSimple($index['name']);

        return 'CREATE ' . ($index['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX ' . $this->quote($index['name']) . ' ON ' . $this->quote($table) . ' (' . $this->columnList($index['columns']) . ')';
    }

    private function foreignKey(Column $column): string
    {
        Identifier::assertSimple((string) $column->referencesTable);
        Identifier::assertSimple($column->referencesColumn);
        $action = $column->onDelete === null ? '' : ' ON DELETE ' . match ($column->onDelete) {
            'cascade' => 'CASCADE',
            'restrict' => 'RESTRICT',
            'set null' => 'SET NULL',
            'no action' => 'NO ACTION',
            default => throw new SchemaException('onDelete() must be "cascade", "restrict", "set null" or "no action".'),
        };

        return 'FOREIGN KEY (' . $this->quote($column->name) . ') REFERENCES ' . $this->quote((string) $column->referencesTable) . ' (' . $this->quote($column->referencesColumn) . ')' . $action;
    }
}
