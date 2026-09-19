<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use BackedEnum;

/**
 * The fluent API used inside EntityMap::define(). Column names default to snake_case of the
 * property name. Nothing here touches the database or reflects on the entity; the MetadataFactory
 * validates the result.
 *
 * @api
 */
final class MapBuilder
{
    public ?string $table = null;

    public ?ColumnSpec $id = null;

    public bool $generatedId = true;

    /** @var list<ColumnSpec> */
    public array $columns = [];

    /** @var list<RelationSpec> */
    public array $relations = [];

    public ?string $softDeleteProperty = null;

    public ?string $versionProperty = null;

    /** @var list<class-string> */
    public array $scopes = [];

    /** @var list<string> */
    public array $errors = [];

    public function table(string $table): static
    {
        $this->table = $table;

        return $this;
    }

    /**
     * The primary key. By default an auto-generated integer (the entity property must be a
     * non-readonly nullable property, e.g. `public private(set) ?int $id = null`).
     */
    public function id(string $property = 'id', ?string $column = null, Type $type = Type::Int, bool $generated = true): ColumnSpec
    {
        if ($this->id !== null) {
            $this->errors[] = 'The map declares more than one id().';
        }

        $this->generatedId = $generated;

        return $this->id = new ColumnSpec($property, $column ?? self::snake($property), $type);
    }

    public function string(string $property, ?string $column = null): ColumnSpec
    {
        return $this->add($property, $column, Type::String);
    }

    public function int(string $property, ?string $column = null): ColumnSpec
    {
        return $this->add($property, $column, Type::Int);
    }

    public function float(string $property, ?string $column = null): ColumnSpec
    {
        return $this->add($property, $column, Type::Float);
    }

    public function bool(string $property, ?string $column = null): ColumnSpec
    {
        return $this->add($property, $column, Type::Bool);
    }

    /** A DateTimeImmutable, stored as UTC. */
    public function dateTime(string $property, ?string $column = null): ColumnSpec
    {
        return $this->add($property, $column, Type::DateTime);
    }

    /** An array stored as JSON text (never unserialize()d objects). */
    public function json(string $property, ?string $column = null): ColumnSpec
    {
        return $this->add($property, $column, Type::Json);
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    public function enum(string $property, string $enum, ?string $column = null): ColumnSpec
    {
        return $this->register(new ColumnSpec($property, $column ?? self::snake($property), Type::Enum, $enum));
    }

    /**
     * Soft deletes: remove() sets the timestamp and every query hides such rows.
     */
    public function softDeletes(string $property = 'deletedAt', ?string $column = null): ColumnSpec
    {
        $this->softDeleteProperty = $property;

        return $this->add($property, $column, Type::DateTime)->nullable();
    }

    /**
     * Optimistic locking: updates and deletes only apply while the stored version is the loaded one.
     */
    public function version(string $property = 'version', ?string $column = null): ColumnSpec
    {
        $this->versionProperty = $property;

        return $this->add($property, $column, Type::Int);
    }

    /**
     * A global scope applied to every query of this entity (tenant filters and the like).
     *
     * @param class-string $scope a class implementing Trunk\Orm\Repository\Scope
     */
    public function scope(string $scope): static
    {
        $this->scopes[] = $scope;

        return $this;
    }

    /**
     * @param class-string $target
     * @param string       $foreignKey property on the target that holds this entity's key
     */
    public function hasMany(string $name, string $target, string $foreignKey, ?string $localKey = null): static
    {
        return $this->relate(new RelationSpec($name, RelationKind::HasMany, $target, $foreignKey, $localKey));
    }

    /**
     * @param class-string $target
     * @param string       $foreignKey property on the target that holds this entity's key
     */
    public function hasOne(string $name, string $target, string $foreignKey, ?string $localKey = null): static
    {
        return $this->relate(new RelationSpec($name, RelationKind::HasOne, $target, $foreignKey, $localKey));
    }

    /**
     * @param class-string $target
     * @param string       $foreignKey property on this entity that holds the target's key
     * @param string|null  $ownerKey   property on the target (default: its id)
     */
    public function belongsTo(string $name, string $target, string $foreignKey, ?string $ownerKey = null): static
    {
        return $this->relate(new RelationSpec($name, RelationKind::BelongsTo, $target, $foreignKey, $ownerKey));
    }

    /**
     * @param class-string $target
     * @param string       $pivotTable         the link table
     * @param string       $pivotLocalColumn   pivot column holding this entity's key
     * @param string       $pivotForeignColumn pivot column holding the target's key
     */
    public function belongsToMany(string $name, string $target, string $pivotTable, string $pivotLocalColumn, string $pivotForeignColumn): static
    {
        return $this->relate(new RelationSpec($name, RelationKind::BelongsToMany, $target, null, null, $pivotTable, $pivotLocalColumn, $pivotForeignColumn));
    }

    public static function snake(string $property): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $property) ?? $property);
    }

    private function add(string $property, ?string $column, Type $type): ColumnSpec
    {
        return $this->register(new ColumnSpec($property, $column ?? self::snake($property), $type));
    }

    private function register(ColumnSpec $spec): ColumnSpec
    {
        $this->columns[] = $spec;

        return $spec;
    }

    private function relate(RelationSpec $relation): static
    {
        $this->relations[] = $relation;

        return $this;
    }
}
