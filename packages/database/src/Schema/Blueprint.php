<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

use Trunk\Database\Exception\SchemaException;
use Trunk\Database\Query\Identifier;

/**
 * Collects what should happen to one table. Every name is validated as a plain identifier.
 *
 * @api
 */
final class Blueprint
{
    /** @var list<Column> */
    private array $columns = [];

    /** @var list<array{type: string, columns: list<string>, name: ?string}> */
    private array $indexes = [];

    /** @var list<string> */
    private array $dropColumns = [];

    /** @var list<string> */
    private array $dropIndexes = [];

    /** @var list<string> */
    private array $primaryKey = [];

    public function __construct(public readonly string $table)
    {
        Identifier::assertSimple($table);
    }

    public function id(string $name = 'id'): Column
    {
        return $this->add($name, 'id');
    }

    public function string(string $name, int $length = 255): Column
    {
        return $this->add($name, 'string', [max(1, min(65535, $length))]);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function integer(string $name): Column
    {
        return $this->add($name, 'integer');
    }

    public function bigInteger(string $name): Column
    {
        return $this->add($name, 'bigInteger');
    }

    public function boolean(string $name): Column
    {
        return $this->add($name, 'boolean');
    }

    public function decimal(string $name, int $precision = 10, int $scale = 2): Column
    {
        return $this->add($name, 'decimal', [max(1, min(65, $precision)), max(0, min(30, $scale))]);
    }

    public function float(string $name): Column
    {
        return $this->add($name, 'float');
    }

    public function date(string $name): Column
    {
        return $this->add($name, 'date');
    }

    public function dateTime(string $name): Column
    {
        return $this->add($name, 'dateTime');
    }

    public function timestamp(string $name): Column
    {
        return $this->add($name, 'timestamp');
    }

    public function json(string $name): Column
    {
        return $this->add($name, 'json');
    }

    public function uuid(string $name): Column
    {
        return $this->add($name, 'uuid');
    }

    public function binary(string $name): Column
    {
        return $this->add($name, 'binary');
    }

    /**
     * An unsigned big integer column that references another table.
     */
    public function foreignId(string $name): Column
    {
        return $this->add($name, 'bigInteger')->unsigned();
    }

    /**
     * `created_at` and `updated_at`, both nullable timestamps.
     */
    public function timestamps(): void
    {
        $this->timestamp('created_at')->nullable();
        $this->timestamp('updated_at')->nullable();
    }

    /**
     * @param list<string>|string $columns
     */
    public function index(array|string $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'index', 'columns' => $this->names($columns), 'name' => $name];
    }

    /**
     * @param list<string>|string $columns
     */
    public function unique(array|string $columns, ?string $name = null): void
    {
        $this->indexes[] = ['type' => 'unique', 'columns' => $this->names($columns), 'name' => $name];
    }

    /**
     * A composite primary key.
     *
     * @param list<string> $columns
     */
    public function primary(array $columns): void
    {
        $this->primaryKey = $this->names($columns);
    }

    public function dropColumn(string ...$columns): void
    {
        foreach ($columns as $column) {
            Identifier::assertSimple($column);
            $this->dropColumns[] = $column;
        }
    }

    public function dropIndex(string $name): void
    {
        Identifier::assertSimple($name);
        $this->dropIndexes[] = $name;
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Column-level unique()/index() plus explicit ones, with their generated names.
     *
     * @return list<array{type: string, columns: list<string>, name: string}>
     */
    public function indexes(): array
    {
        $all = $this->indexes;

        foreach ($this->columns as $column) {
            if ($column->unique) {
                $all[] = ['type' => 'unique', 'columns' => [$column->name], 'name' => null];
            }

            if ($column->index) {
                $all[] = ['type' => 'index', 'columns' => [$column->name], 'name' => null];
            }
        }

        return array_map(fn(array $index): array => ['type' => $index['type'], 'columns' => $index['columns'], 'name' => $index['name'] ?? $this->indexName($index['type'], $index['columns'])], $all);
    }

    /**
     * @return list<string>
     */
    public function primaryKey(): array
    {
        return $this->primaryKey;
    }

    /**
     * @return list<string>
     */
    public function droppedColumns(): array
    {
        return $this->dropColumns;
    }

    /**
     * @return list<string>
     */
    public function droppedIndexes(): array
    {
        return $this->dropIndexes;
    }

    /**
     * @param list<int> $parameters
     */
    private function add(string $name, string $type, array $parameters = []): Column
    {
        Identifier::assertSimple($name);

        return $this->columns[] = new Column($name, $type, $parameters);
    }

    /**
     * @param list<string>|string $columns
     *
     * @return list<string>
     */
    private function names(array|string $columns): array
    {
        $list = \is_string($columns) ? [$columns] : $columns;

        if ($list === []) {
            throw new SchemaException('An index needs at least one column.');
        }

        foreach ($list as $column) {
            Identifier::assertSimple($column);
        }

        return $list;
    }

    /**
     * @param list<string> $columns
     */
    private function indexName(string $type, array $columns): string
    {
        $name = $this->table . '_' . implode('_', $columns) . '_' . $type;

        if (\strlen($name) > 63) {
            throw new SchemaException(\sprintf('The generated index name "%s" is longer than 63 characters; pass an explicit shorter name.', $name));
        }

        return $name;
    }
}
