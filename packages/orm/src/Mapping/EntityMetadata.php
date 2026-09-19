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
