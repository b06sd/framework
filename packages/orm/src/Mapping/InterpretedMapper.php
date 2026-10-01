<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use BcMath\Number;
use Trunk\Orm\Exception\OrmException;

/**
 * The development mapper: the same conversions as the generated code, driven by metadata.
 * It uses named-argument construction and get_object_vars(), not reflection.
 */
final readonly class InterpretedMapper implements Mapper
{
    public function __construct(private EntityMetadata $metadata) {}

    public function hydrate(array $row): object
    {
        $arguments = [];

        foreach ($this->metadata->columns as $property => $column) {
            if ($column->hidden && !\array_key_exists($column->column, $row)) {
                continue;
            }

            $arguments[$property] = Convert::toPhp($column->type, $row[$column->column] ?? null, $column->enum, $this->metadata->class, $column->column, $column->nullable, $column->scale);
        }

        return new ($this->metadata->class)(...$arguments);
    }

    public function extract(object $entity): array
    {
        if (!$entity instanceof $this->metadata->class) {
            throw new OrmException(\sprintf('Expected %s, got %s.', $this->metadata->class, $entity::class));
        }

        $values = get_object_vars($entity);
        $row = [];

        foreach ($this->metadata->columns as $property => $column) {
            $value = $values[$property] ?? null;
            $row[$column->column] = $column->type === Type::Decimal && $column->scale !== null
                ? Convert::decimalToDb($value instanceof Number || $value === null ? $value : throw new OrmException(\sprintf('%s::$%s must be a %s.', $this->metadata->class, $property, Number::class)), $column->scale, $this->metadata->class, $property)
                : Convert::toDb($column->type, $value);
        }

        return $row;
    }

    public function assignId(object $entity, int|string $id): void
    {
        $property = $this->metadata->idProperty;
        (function () use ($property, $id): void {
            $this->{$property} = $id;
        })->call($entity);
    }
}
