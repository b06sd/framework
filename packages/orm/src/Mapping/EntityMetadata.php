<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use Trunk\Orm\Exception\UnknownProperty;

/**
 * Immutable, validated description of one mapped entity. Built from an EntityMap at build time
 * (or on first use in development) and stored as plain data in `build/orm.php`.
 */
final readonly class EntityMetadata
{
    /**
     * @param class-string                        $class
     * @param array<string, ColumnMetadata>       $columns   property => column (the id column included)
     * @param array<string, RelationMetadata>     $relations name => relation
     * @param list<class-string>                  $scopes
     * @param list<string>                        $selectColumns the columns a normal query reads (hidden ones excluded), precompiled
     * @param list<string>                        $keyProperties the properties of a composite key; empty for a key of one column ($idProperty)
     */
    public function __construct(
        public string $class,
        public string $table,
        public string $idProperty,
        public bool $generatedId,
        public array $columns,
        public array $relations,
        public ?string $softDeleteProperty,
        public ?string $versionProperty,
        public array $scopes,
        public array $selectColumns,
        public array $keyProperties = [],
    ) {}

    /**
     * Every column including hidden ones (for withHidden()).
     *
     * @return list<string>
     */
    public function allColumns(): array
    {
        return array_values(array_map(static fn(ColumnMetadata $c): string => $c->column, $this->columns));
    }

    public function idColumn(): ColumnMetadata
    {
        return $this->columns[$this->idProperty];
    }

    public function hasCompositeKey(): bool
    {
        return \count($this->keyProperties) > 1;
    }

    /**
     * The primary key's columns: one, or several for a composite key.
     *
     * @return non-empty-list<ColumnMetadata>
     */
    public function keyColumns(): array
    {
        $columns = $this->hasCompositeKey() ? array_map(fn(string $property): ColumnMetadata => $this->columns[$property], $this->keyProperties) : [];

        return $columns === [] ? [$this->idColumn()] : $columns;
    }

    /**
     * The identity-map key of a stored row (column => value): the id as text, or for a composite key the
     * key values in order. Null when the row lacks a usable key.
     *
     * @param array<array-key, mixed> $row
     */
    public function identity(array $row): ?string
    {
        $parts = [];

        foreach ($this->keyColumns() as $column) {
            $value = $row[$column->column] ?? null;

            if (!\is_int($value) && !\is_string($value)) {
                return null;
            }

            $parts[] = (string) $value;
        }

        return \count($parts) === 1 ? $parts[0] : json_encode($parts, \JSON_THROW_ON_ERROR);
    }

    /**
     * Any mapped property (trusted code).
     */
    public function column(string $property): ColumnMetadata
    {
        return $this->columns[$property] ?? throw UnknownProperty::for($this->class, $property, 'mapped');
    }

    public function softDeleteColumn(): ?ColumnMetadata
    {
        return $this->softDeleteProperty === null ? null : $this->columns[$this->softDeleteProperty];
    }

    public function versionColumn(): ?ColumnMetadata
    {
        return $this->versionProperty === null ? null : $this->columns[$this->versionProperty];
    }

    public function relation(string $name): RelationMetadata
    {
        return $this->relations[$name] ?? throw UnknownProperty::for($this->class, $name, 'relation');
    }
}
