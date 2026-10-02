<?php

declare(strict_types=1);

namespace Trunk\Orm\Repository;

use Trunk\Orm\Exception\EntityNotFound;
use Trunk\Orm\Exception\InvalidFilter;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\UnknownProperty;
use Trunk\Orm\Mapping\ColumnMetadata;
use Trunk\Orm\Mapping\Convert;
use Trunk\Orm\Mapping\EntityMetadata;
use Trunk\Orm\Mapping\Mapper;
use Trunk\Orm\Mapping\Type;
use Trunk\Orm\UnitOfWork\EntityManager;

/**
 * Reads one entity type. Get it from the EntityManager (`$manager->repository(Customer::class)`),
 * or compose it inside your own repository class. There is nothing to extend.
 *
 * @template T of object
 *
 * @api
 */
final readonly class Repository
{
    /**
     * @param class-string<T> $class
     *
     * @internal wired by the container, not part of the API
     */
    public function __construct(
        private EntityManager $manager,
        private string $class,
        private EntityMetadata $metadata,
        private Mapper $mapper,
    ) {}

    /**
     * @return Query<T>
     */
    public function query(): Query
    {
        return new Query($this->manager, $this->class, $this->metadata, $this->mapper);
    }

    /**
     * By primary key: the id, or for a composite key every key property by name
     * (`find(['orderId' => 7, 'lineNo' => 2])`).
     *
     * @param int|string|array<string, int|string> $id
     *
     * @return T|null
     */
    public function find(int|string|array $id): ?object
    {
        $key = $this->key($id);
        $row = [];

        foreach ($this->metadata->keyColumns() as $column) {
            $row[$column->column] = $key[$column->property];
        }

        $identity = $this->metadata->identity($row);
        $known = $identity === null ? null : $this->manager->unitOfWork()->find($this->class, $identity);

        if ($known instanceof $this->class) {
            return $known;
        }

        $query = $this->query();

        foreach ($key as $property => $value) {
            $query = $query->where($property, $value);
        }

        return $query->first();
    }

    /**
     * @param int|string|array<string, int|string> $id
     *
     * @return T
     *
     * @throws EntityNotFound
     */
    public function findOrFail(int|string|array $id): object
    {
        return $this->find($id) ?? throw EntityNotFound::for($this->class);
    }

    /**
     * @return list<T>
     */
    public function all(): array
    {
        return $this->query()->get();
    }

    /**
     * Turns untrusted input (a decoded request body) into typed constructor values, for the
     * properties in `$allow` only. There is no "fill everything" path: unknown keys, keys outside
     * the allowlist, the id and the version are refused, so a client cannot set fields you did
     * not list.
     *
     * @param array<array-key, mixed> $input
     * @param list<string>            $allow property names
     *
     * @return array<string, mixed> property => typed value, ready for named constructor arguments
     *
     * @throws UnknownProperty|InvalidFilter
     */
    public function input(array $input, array $allow): array
    {
        $values = [];

        foreach ($allow as $property) {
            $this->metadata->column($property);

            if ($property === $this->metadata->idProperty || $property === $this->metadata->versionProperty || $property === $this->metadata->softDeleteProperty) {
                throw UnknownProperty::for($this->class, $property, 'writable');
            }
        }

        foreach ($input as $key => $value) {
            if (!\in_array((string) $key, $allow, true)) {
                throw new InvalidFilter('The input contains a field that is not accepted.');
            }
        }

        foreach ($allow as $property) {
            if (!\array_key_exists($property, $input)) {
                continue;
            }

            $column = $this->metadata->column($property);
            $value = $input[$property];

            try {
                $values[$property] = match (true) {
                    $value === null => $column->nullable ? null : throw new InvalidFilter(\sprintf('"%s" cannot be null.', $property)),
                    $column->type === Type::Json => \is_array($value) ? $value : throw new InvalidFilter(\sprintf('"%s" must be an object or list.', $property)),
                    $column->type === Type::Enum && $column->enum !== null => $column->enum::tryFrom(\is_string($value) || \is_int($value) ? $value : '') ?? throw new InvalidFilter(\sprintf('"%s" is not an accepted value.', $property)),
                    default => Convert::toPhp($column->type, $value, $column->enum, $this->class, $column->column, false, $column->scale),
                };
            } catch (\Trunk\Orm\Exception\HydrationException) {
                throw new InvalidFilter(\sprintf('"%s" is not a valid %s.', $property, $column->type->value));
            }
        }

        return $values;
    }

    /**
     * The key as property => value, checked against the map.
     *
     * @param int|string|array<string, int|string> $id
     *
     * @return array<string, int|string>
     */
    private function key(int|string|array $id): array
    {
        $properties = array_map(static fn(ColumnMetadata $c): string => $c->property, $this->metadata->keyColumns());

        if (!\is_array($id)) {
            return \count($properties) === 1 ? [$properties[0] => $id] : throw new OrmException(\sprintf('%s has a composite key: find() it by %s.', $this->class, "['" . implode("' => ..., '", $properties) . "' => ...]"));
        }

        if (array_keys($id) !== $properties && (array_diff($properties, array_keys($id)) !== [] || \count($id) !== \count($properties))) {
            throw new OrmException(\sprintf('%s is found by %s, exactly.', $this->class, implode(', ', $properties)));
        }

        $key = [];

        foreach ($properties as $property) {
            $key[$property] = $id[$property];
        }

        return $key;
    }
}
