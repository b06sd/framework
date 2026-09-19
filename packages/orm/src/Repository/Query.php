<?php

declare(strict_types=1);

namespace Trunk\Orm\Repository;

use BackedEnum;
use Closure;
use Generator;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Query\QueryBuilder;
use Trunk\Orm\Exception\HydrationException;
use Trunk\Orm\Exception\InvalidFilter;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\UnknownProperty;
use Trunk\Orm\Mapping\ColumnMetadata;
use Trunk\Orm\Mapping\Convert;
use Trunk\Orm\Mapping\EntityMetadata;
use Trunk\Orm\Mapping\Mapper;
use Trunk\Orm\Mapping\Type;
use Trunk\Orm\Relation\EagerLoader;
use Trunk\Orm\UnitOfWork\EntityManager;

/**
 * A query over one entity, written in property names. Immutable: every method returns a new
 * query. Property names are resolved through the entity map, so only mapped properties can ever
 * become column names; values are always bindings; soft-delete and scopes are always applied
 * (and always ANDed around your own conditions, so an orWhere() cannot escape them).
 *
 * Two levels of access: where()/orderBy() take any mapped property (your own code), while
 * filter()/sortBy() take untrusted request input and only reach properties the map marked
 * filterable()/sortable().
 *
 * @template T of object
 *
 * @api
 */
final class Query
{
    private const int MAX_FILTER_VALUES = 100;

    private const int MAX_SORTS = 3;

    /** @var list<Closure(QueryBuilder): QueryBuilder> */
    private array $conditions = [];

    /** @var list<array{string, string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    /** @var list<string> */
    private array $with = [];

    private bool $readOnly = false;

    private bool $withHidden = false;

    private string $trashed = 'without';

    /** @var list<class-string> */
    private array $skippedScopes = [];

    /**
     * @param class-string<T> $class
     *
     * @internal wired by the container, not part of the API
     */
    public function __construct(
        private readonly EntityManager $manager,
        private readonly string $class,
        private readonly EntityMetadata $metadata,
        private readonly Mapper $mapper,
    ) {}

    // ---- conditions (trusted code: any mapped property) ----

    public function where(string $property, mixed $operator = null, mixed $value = null): static
    {
        return $this->condition('and', $property, $operator, $value, \func_num_args());
    }

    public function orWhere(string $property, mixed $operator = null, mixed $value = null): static
    {
        return $this->condition('or', $property, $operator, $value, \func_num_args());
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $property, array $values): static
    {
        $column = $this->metadata->column($property);

        $bound = $this->dbValues($column, $values);

        return $this->add(static fn(QueryBuilder $q): QueryBuilder => $q->whereIn($column->column, $bound));
    }

    public function whereNull(string $property): static
    {
        $column = $this->metadata->column($property)->column;

        return $this->add(static fn(QueryBuilder $q): QueryBuilder => $q->whereNull($column));
    }

    public function whereNotNull(string $property): static
    {
        $column = $this->metadata->column($property)->column;

        return $this->add(static fn(QueryBuilder $q): QueryBuilder => $q->whereNotNull($column));
    }

    /**
     * Used by the eager loader with columns taken from validated metadata, never from input.
     *
     * @internal
     *
     * @param list<int|string> $values
     */
    public function whereColumnIn(string $column, array $values): static
    {
        return $this->add(static fn(QueryBuilder $q): QueryBuilder => $q->whereIn($column, $values));
    }

    public function orderBy(string $property, string $direction = 'asc'): static
    {
        $clone = clone $this;
        $clone->orders[] = [$this->metadata->column($property)->column, $direction];

        return $clone;
    }

    public function limit(int $limit): static
    {
        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function offset(int $offset): static
    {
        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    // ---- untrusted input: only filterable / sortable properties ----

    /**
     * Applies request filters (`['status' => 'active', 'name' => ['Ada', 'Grace']]`). Only
     * properties marked filterable() are accepted; a list means "one of". Anything else throws.
     *
     * @param array<array-key, mixed> $input
     *
     * @throws UnknownProperty|InvalidFilter
     */
    public function filter(array $input): static
    {
        $query = $this;

        foreach ($input as $property => $value) {
            $column = $this->metadata->columns[(string) $property] ?? null;

            if ($column === null || !$column->filterable) {
                throw UnknownProperty::for($this->class, (string) $property, 'filterable');
            }

            if (\is_array($value)) {
                if (!array_is_list($value) || $value === [] || \count($value) > self::MAX_FILTER_VALUES) {
                    throw new InvalidFilter(\sprintf('The filter for "%s" must be a scalar or a list of 1 to %d scalars.', $column->property, self::MAX_FILTER_VALUES));
                }

                $query = $query->whereIn($column->property, $value);

                continue;
            }

            $query = $query->where($column->property, '=', $value);
        }

        return $query;
    }

    /**
     * Applies a sort such as `name` or `-createdAt,name` (a leading `-` is descending). Only
     * properties marked sortable() are accepted, at most three.
     *
     * @throws UnknownProperty|InvalidFilter
     */
    public function sortBy(string $spec): static
    {
        $parts = explode(',', $spec);

        if (\count($parts) > self::MAX_SORTS) {
            throw new InvalidFilter(\sprintf('At most %d sort fields are allowed.', self::MAX_SORTS));
        }

        $query = $this;

        foreach ($parts as $part) {
            $descending = str_starts_with($part, '-');
            $name = $descending ? substr($part, 1) : $part;
            $column = $this->metadata->columns[$name] ?? null;

            if ($column === null || !$column->sortable) {
                throw UnknownProperty::for($this->class, $name, 'sortable');
            }

            $query = $query->orderBy($column->property, $descending ? 'desc' : 'asc');
        }

        return $query;
    }

    // ---- options ----

    /**
     * Loads relations explicitly with one query each (`'orders'`, `'orders.items'`).
     */
    public function with(string ...$relations): static
    {
        foreach ($relations as $path) {
            $this->metadata->relation(explode('.', $path)[0]);
        }

        $clone = clone $this;
        $clone->with = [...$this->with, ...array_values($relations)];

        return $clone;
    }

    /**
     * Results are plain objects: not tracked, no snapshots, no identity map. Fastest for read-only use.
     */
    public function readOnly(): static
    {
        $clone = clone $this;
        $clone->readOnly = true;

        return $clone;
    }

    /**
     * Also reads hidden() columns (a password hash, say). Off by default. An entity already loaded
     * in this unit of work is returned as it is, so load hidden columns before anything else does.
     */
    public function withHidden(): static
    {
        $clone = clone $this;
        $clone->withHidden = true;

        return $clone;
    }

    public function withTrashed(): static
    {
        $clone = clone $this;
        $clone->trashed = 'with';

        return $clone;
    }

    public function onlyTrashed(): static
    {
        $clone = clone $this;
        $clone->trashed = 'only';

        return $clone;
    }

    /**
     * The one way to skip a global scope. Deliberately explicit so it is easy to find in review.
     *
     * @param class-string $scope
     */
    public function withoutScope(string $scope): static
    {
        $clone = clone $this;
        $clone->skippedScopes[] = $scope;

        return $clone;
    }

    // ---- reading ----

    /**
     * @return list<T>
     */
    public function get(): array
    {
        $entities = $this->hydrate($this->guarded(fn(): array => $this->builder()->get()));

        if ($this->with !== []) {
            new EagerLoader($this->manager)->load($entities, $this->metadata, $this->with);
        }

        return $entities;
    }

    /**
     * @return T|null
     */
    public function first(): ?object
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function count(): int
    {
        return $this->guarded(fn(): int => $this->builder(false)->count());
    }

    public function exists(): bool
    {
        return $this->guarded(fn(): bool => $this->builder(false)->exists());
    }

    /**
     * @return Page<T>
     */
    public function paginate(int $page = 1, int $perPage = 20): Page
    {
        if ($page < 1 || $perPage < 1 || $perPage > 1000) {
            throw new InvalidFilter('page must be at least 1 and perPage between 1 and 1000.');
        }

        if ($page > intdiv(\PHP_INT_MAX, $perPage)) {
            throw new InvalidFilter('page is too large.');
        }

        return new Page($this->limit($perPage)->offset(($page - 1) * $perPage)->get(), $this->count(), $page, $perPage);
    }

    /**
     * Streams a large result in primary-key order, `$chunk` rows at a time, so memory stays flat.
     * Entities are read-only (untracked).
     *
     * @return Generator<int, T>
     */
    public function cursor(int $chunk = 500): Generator
    {
        if ($chunk < 1 || $chunk > 5000) {
            throw new InvalidFilter('The cursor chunk size must be between 1 and 5000.');
        }

        $idColumn = $this->metadata->idColumn()->column;
        $base = $this->readOnly();
        $last = null;

        while (true) {
            $builder = $base->builder()->orderBy($idColumn)->limit($chunk);

            if ($last !== null) {
                $builder = $builder->where($idColumn, '>', $last);
            }

            $rows = $this->guarded(static fn(): array => $builder->get());

            if ($rows === []) {
                return;
            }

            $entities = $base->hydrate($rows);

            if ($this->with !== []) {
                new EagerLoader($this->manager)->load($entities, $this->metadata, $this->with);
            }

            yield from $entities;

            $lastId = $rows[\count($rows) - 1][$idColumn] ?? null;

            if (\count($rows) < $chunk || !(\is_int($lastId) || \is_string($lastId))) {
                return;
            }

            $last = $lastId;
        }
    }

    // ---- internals ----

    private function condition(string $boolean, string $property, mixed $operator, mixed $value, int $arguments): static
    {
        $column = $this->metadata->column($property);

        if ($arguments === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = \is_string($operator) ? $operator : '';
        $bound = strtolower($operator) === 'like' ? $value : $this->dbValue($column, $value);

        return $this->add(static fn(QueryBuilder $q): QueryBuilder => $boolean === 'or' ? $q->orWhere($column->column, $operator, $bound) : $q->where($column->column, $operator, $bound));
    }

    /**
     * @param Closure(QueryBuilder): QueryBuilder $condition
     */
    private function add(Closure $condition): static
    {
        $clone = clone $this;
        $clone->conditions[] = $condition;

        return $clone;
    }

    private function builder(bool $ordered = true): QueryBuilder
    {
        $builder = $this->manager->connection()->table($this->metadata->table)->select(...($this->withHidden ? $this->metadata->allColumns() : $this->metadata->selectColumns));

        if ($this->conditions !== []) {
            $conditions = $this->conditions;
            $builder = $builder->where(static function (QueryBuilder $group) use ($conditions): QueryBuilder {
                foreach ($conditions as $condition) {
                    $group = $condition($group);
                }

                return $group;
            });
        }

        $softDelete = $this->metadata->softDeleteColumn();

        if ($softDelete !== null && $this->trashed !== 'with') {
            $builder = $this->trashed === 'only' ? $builder->whereNotNull($softDelete->column) : $builder->whereNull($softDelete->column);
        }

        foreach ($this->metadata->scopes as $scope) {
            if (!\in_array($scope, $this->skippedScopes, true)) {
                $builder = $this->manager->scope($scope)->apply($builder);
            }
        }

        if ($ordered) {
            foreach ($this->orders as [$column, $direction]) {
                $builder = $builder->orderBy($column, $direction);
            }

            if ($this->limit !== null) {
                $builder = $builder->limit($this->limit);
            }

            if ($this->offset !== null) {
                $builder = $builder->offset($this->offset);
            }
        }

        return $builder;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<T>
     */
    private function hydrate(array $rows): array
    {
        $class = $this->class;
        $unit = $this->manager->unitOfWork();
        $idColumn = $this->metadata->idColumn()->column;
        $entities = [];

        foreach ($rows as $row) {
            if ($this->readOnly) {
                $entity = $this->mapper->hydrate($row);
            } else {
                $id = $row[$idColumn] ?? null;

                if (!\is_int($id) && !\is_string($id)) {
                    throw new OrmException(\sprintf('%s row has no usable primary key.', $class));
                }

                $entity = $unit->find($class, $id) ?? $unit->manage($fresh = $this->mapper->hydrate($row), $this->metadata, $id, $this->mapper->extract($fresh));
            }

            if ($entity instanceof $class) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * The backing value of the enum case a request value names. A request delivers strings, so a
     * whole-number string is accepted for an int-backed enum; any other type mismatch is a rejected
     * filter (never a TypeError, which would be a 500 for a hostile query string).
     *
     * @param class-string<BackedEnum> $enum
     */
    private function enumValue(string $enum, string|int $value, string $property): string|int
    {
        $cases = $enum::cases();
        $intBacked = $cases !== [] && \is_int($cases[0]->value);

        if ($intBacked && \is_string($value) && preg_match('/^-?\d{1,18}$/D', $value) === 1) {
            $value = (int) $value;
        }

        foreach ($cases as $case) {
            if ($case->value === $value) {
                return $case->value;
            }
        }

        throw new InvalidFilter(\sprintf('Not an accepted value for "%s".', $property));
    }

    /**
     * Runs a read. A database that rejects a value the caller put in a condition (an integer too big
     * for the column, text that is not valid in the database's encoding) reports a data exception;
     * that is a bad filter, not a server fault, so it becomes InvalidFilter without the database's
     * message. Other failures, and any failure of a query with no conditions, pass through.
     *
     * @template R
     *
     * @param Closure(): R $run
     *
     * @return R
     */
    private function guarded(Closure $run): mixed
    {
        try {
            return $run();
        } catch (QueryException $e) {
            if ($this->conditions !== [] && str_starts_with($e->sqlState, '22')) {
                throw new InvalidFilter('A filter value is not acceptable for its column.', 0, $e);
            }

            throw $e;
        }
    }

    private function dbValue(ColumnMetadata $column, mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }

        if (\is_string($value) && $this->manager->connection()->driver()->name() === 'pgsql' && (str_contains($value, "\0") || !mb_check_encoding($value, 'UTF-8'))) {
            // PostgreSQL silently cuts text at a NUL byte (so "a\0b" would match "a") and rejects invalid
            // UTF-8 outright: neither is a value anyone means to search for. Other servers keep the bytes.
            throw new InvalidFilter(\sprintf('Not a valid value for "%s".', $column->property));
        }

        try {
            if ($column->type === Type::Enum && (\is_string($value) || \is_int($value)) && $column->enum !== null) {
                return $this->enumValue($column->enum, $value, $column->property);
            }

            if ($column->type === Type::DateTime && \is_string($value)) {
                return Convert::dateTimeToDb(Convert::dateTime($value, $this->class, $column->column));
            }

            if ($value instanceof BackedEnum && $column->type !== Type::Enum) {
                throw new InvalidFilter(\sprintf('Not a valid value for "%s".', $column->property));
            }

            return Convert::toDb($column->type, $value);
        } catch (HydrationException) {
            throw new InvalidFilter(\sprintf('Not a valid %s for "%s".', $column->type->value, $column->property));
        }
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string|int|float|bool|null>
     */
    private function dbValues(ColumnMetadata $column, array $values): array
    {
        return array_map(fn(mixed $v): string|int|float|bool|null => $this->dbValue($column, $v), $values);
    }
}
