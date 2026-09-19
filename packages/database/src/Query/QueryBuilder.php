<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

use Closure;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\InvalidQueryException;

/**
 * Fluent, immutable query builder: every method returns a new builder, so a shared builder can
 * never be changed underneath another caller. Values are always bound, identifiers are validated
 * and quoted, operators come from a whitelist. `Raw` is the only way to write SQL by hand.
 *
 * @api
 */
final class QueryBuilder
{
    private const int MAX_BINDINGS_PER_STATEMENT = 30000;

    /** @var list<string|Raw> */
    private array $columns = ['*'];

    private bool $distinct = false;

    /** @var list<Where> */
    private array $wheres = [];

    /** @var list<array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<Where> */
    private array $havings = [];

    /** @var list<array{string|Raw, string}> */
    private array $orders = [];

    private ?int $limit = null;

    private ?int $offset = null;

    private bool $unrestricted = false;

    public function __construct(private readonly Connection $connection, private readonly string $table)
    {
        Identifier::assert($table);
    }

    public function select(string|Raw ...$columns): self
    {
        foreach ($columns as $column) {
            if (\is_string($column)) {
                Identifier::assert($column);
            }
        }

        $clone = clone $this;
        $clone->columns = $columns === [] ? ['*'] : array_values($columns);

        return $clone;
    }

    public function distinct(): self
    {
        $clone = clone $this;
        $clone->distinct = true;

        return $clone;
    }

    /**
     * `where('a', 5)` means `a = 5`; `where('a', '>', 5)` uses the operator. A null value becomes IS NULL / IS NOT NULL.
     * Pass a closure to group conditions in parentheses.
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->condition('AND', $column, $operator, $value, \func_num_args());
    }

    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->condition('OR', $column, $operator, $value, \func_num_args());
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addWhere(Where::in('AND', $this->column($column), $this->values($values), false));
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addWhere(Where::in('AND', $this->column($column), $this->values($values), true));
    }

    public function whereNull(string $column): self
    {
        return $this->addWhere(Where::null('AND', $this->column($column), false));
    }

    public function whereNotNull(string $column): self
    {
        return $this->addWhere(Where::null('AND', $this->column($column), true));
    }

    public function whereBetween(string $column, mixed $low, mixed $high): self
    {
        return $this->addWhere(Where::between('AND', $this->column($column), Value::normalize($low), Value::normalize($high), false));
    }

    /**
     * Compares two columns (for example in a correlated condition).
     */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        return $this->addWhere(Where::column('AND', $this->column($first), Operator::normalize($operator), $this->column($second)));
    }

    /**
     * Unsafe with user input: the SQL is used as written (bindings are still bound safely).
     */
    public function whereRaw(Raw $raw): self
    {
        return $this->addWhere(Where::raw('AND', $raw));
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    public function orderBy(string|Raw $column, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);

        if ($direction !== 'asc' && $direction !== 'desc') {
            throw new InvalidQueryException('The order direction must be "asc" or "desc".');
        }

        $clone = clone $this;
        $clone->orders[] = [\is_string($column) ? $this->column($column) : $column, strtoupper($direction)];

        return $clone;
    }

    public function groupBy(string ...$columns): self
    {
        $clone = clone $this;

        foreach ($columns as $column) {
            $clone->groups[] = $this->column($column);
        }

        return $clone;
    }

    public function having(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->havings[] = Where::basic('AND', $this->column($column), Operator::normalize($operator), Value::normalize($value));

        return $clone;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0) {
            throw new InvalidQueryException('The limit must not be negative.');
        }

        $clone = clone $this;
        $clone->limit = $limit;

        return $clone;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new InvalidQueryException('The offset must not be negative.');
        }

        $clone = clone $this;
        $clone->offset = $offset;

        return $clone;
    }

    public function forPage(int $page, int $perPage): self
    {
        if ($page < 1 || $perPage < 1) {
            throw new InvalidQueryException('The page and page size must be at least 1.');
        }

        return $this->limit($perPage)->offset(($page - 1) * $perPage);
    }

    /**
     * Allows `update()` and `delete()` without a WHERE clause (otherwise they refuse, to protect against wiping a table).
     */
    public function unrestricted(): self
    {
        $clone = clone $this;
        $clone->unrestricted = true;

        return $clone;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get(): array
    {
        $query = $this->connection->grammar()->compileSelect($this->state());

        return $this->connection->select($query->sql, $query->bindings);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int|string $id, string $key = 'id'): ?array
    {
        return $this->where($key, $id)->first();
    }

    public function value(string $column): mixed
    {
        $row = $this->select($column)->first();

        return $row === null ? null : ($row[$this->resultKey($column)] ?? null);
    }

    /**
     * @return list<mixed>|array<array-key, mixed> the column values, or key => value when `$key` is given
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $rows = $key === null ? $this->select($column)->get() : $this->select($column, $key)->get();
        $result = [];

        foreach ($rows as $row) {
            $value = $row[$this->resultKey($column)] ?? null;

            if ($key === null) {
                $result[] = $value;
            } else {
                $index = $row[$this->resultKey($key)] ?? null;
                $result[\is_int($index) || \is_string($index) ? $index : (\is_scalar($index) ? (string) $index : '')] = $value;
            }
        }

        return $result;
    }

    public function exists(): bool
    {
        $query = $this->connection->grammar()->compileExists($this->state());

        return (bool) $this->connection->scalar($query->sql, $query->bindings);
    }

    public function count(string $column = '*'): int
    {
        $count = $this->aggregate('COUNT', $column);

        return is_numeric($count) ? (int) $count : 0;
    }

    public function sum(string $column): int|float|null
    {
        return $this->numeric($this->aggregate('SUM', $column));
    }

    public function avg(string $column): int|float|null
    {
        return $this->numeric($this->aggregate('AVG', $column));
    }

    public function min(string $column): mixed
    {
        return $this->aggregate('MIN', $column);
    }

    public function max(string $column): mixed
    {
        return $this->aggregate('MAX', $column);
    }

    /**
     * Inserts one row (`['name' => 'Ada']`) or several (`[[...], [...]]`). Returns the rows affected.
     *
     * @param array<array-key, mixed> $values
     */
    public function insert(array $values): int
    {
        $rows = $this->rows($values);

        if ($rows === []) {
            return 0;
        }

        $perStatement = max(1, intdiv(self::MAX_BINDINGS_PER_STATEMENT, max(1, \count($rows[0]))));
        $chunks = array_chunk($rows, $perStatement);
        $run = function () use ($chunks): int {
            $affected = 0;

            foreach ($chunks as $chunk) {
                $query = $this->connection->grammar()->compileInsert($this->table, $chunk);
                $affected += $this->connection->execute($query->sql, $query->bindings);
            }

            return $affected;
        };

        return \count($chunks) > 1 ? $this->connection->transaction($run) : $run();
    }

    /**
     * @param array<string, mixed> $values
     */
    public function insertGetId(array $values, string $idColumn = 'id'): int|string
    {
        Identifier::assertSimple($idColumn);
        $rows = $this->rows($values);
        $grammar = $this->connection->grammar();

        if ($grammar->supportsReturning()) {
            $query = $grammar->compileInsert($this->table, $rows, true, $idColumn);
            $id = $this->connection->scalar($query->sql, $query->bindings);

            return \is_int($id) || \is_string($id) ? $id : (is_numeric($id) ? (int) $id : 0);
        }

        $query = $grammar->compileInsert($this->table, $rows);
        $this->connection->execute($query->sql, $query->bindings);

        return $this->connection->lastInsertId();
    }

    /**
     * Inserts several rows and returns their generated integer ids in row order, in as few
     * statements as the database allows. PostgreSQL and SQLite use RETURNING; the ids of one
     * INSERT are handed out in row order, so the returned ids are sorted and matched to the rows
     * (the order of RETURNING rows is not guaranteed by either database, the id sequence is).
     * MySQL cannot return ids for a multi-row insert, so it inserts row by row in one transaction.
     * The result is checked: a wrong count or a non-increasing id throws instead of mismatching.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    public function insertGetIds(array $rows, string $idColumn = 'id'): array
    {
        Identifier::assertSimple($idColumn);

        if ($rows === []) {
            return [];
        }

        $rows = $this->rows($rows);
        $grammar = $this->connection->grammar();

        $run = function () use ($rows, $grammar, $idColumn): array {
            $ids = [];

            if (!$grammar->supportsReturning()) {
                foreach ($rows as $row) {
                    $id = $this->insertGetId($row, $idColumn);
                    $ids[] = \is_int($id) ? $id : (int) $id;
                }

                return $ids;
            }

            $perStatement = max(1, intdiv(self::MAX_BINDINGS_PER_STATEMENT, max(1, \count($rows[0]))));

            foreach (array_chunk($rows, $perStatement) as $chunk) {
                $query = $grammar->compileInsert($this->table, $chunk, true, $idColumn);
                $returned = [];

                foreach ($this->connection->select($query->sql, $query->bindings) as $row) {
                    $id = $row[$idColumn] ?? null;

                    if (!\is_int($id) && !(\is_string($id) && preg_match('/^\d{1,18}$/D', $id) === 1)) {
                        throw new InvalidQueryException('The database returned a generated id that is not an integer; insertGetIds() only supports integer ids.');
                    }

                    $returned[] = (int) $id;
                }

                sort($returned);

                if (\count($returned) !== \count($chunk) || \count(array_unique($returned)) !== \count($returned)) {
                    throw new InvalidQueryException('The database returned an unexpected number of generated ids for a batch insert.');
                }

                array_push($ids, ...$returned);
            }

            return $ids;
        };

        return $this->connection->transaction($run);
    }

    /**
     * @param array<string, scalar|Raw|null> $values
     */
    public function update(array $values): int
    {
        $this->assertRestricted('update');
        $query = $this->connection->grammar()->compileUpdate($this->state(), $values);

        return $this->connection->execute($query->sql, $query->bindings);
    }

    public function delete(): int
    {
        $this->assertRestricted('delete');
        $query = $this->connection->grammar()->compileDelete($this->state());

        return $this->connection->execute($query->sql, $query->bindings);
    }

    public function toSql(): string
    {
        return $this->connection->grammar()->compileSelect($this->state())->sql;
    }

    /**
     * @return list<scalar|null>
     */
    public function getBindings(): array
    {
        return $this->connection->grammar()->compileSelect($this->state())->bindings;
    }

    private function state(): QueryState
    {
        return new QueryState($this->table, $this->columns, $this->distinct, $this->wheres, $this->joins, $this->groups, $this->havings, $this->orders, $this->limit, $this->offset);
    }

    private function condition(string $boolean, string|Closure $column, mixed $operator, mixed $value, int $arguments): self
    {
        if ($column instanceof Closure) {
            $nested = new self($this->connection, $this->table);
            $built = $column($nested) ?? $nested;

            if (!$built instanceof self || $built->wheres === []) {
                throw new InvalidQueryException('A grouped where() closure must add at least one condition and return the builder.');
            }

            return $this->addWhere(Where::nested($boolean, $built->wheres));
        }

        if ($arguments === 2) {
            $value = $operator;
            $operator = '=';
        }

        $operator = Operator::normalize(\is_string($operator) ? $operator : '');
        $normalized = Value::normalize($value);
        $column = $this->column($column);

        if ($normalized === null) {
            return match ($operator) {
                '=' => $this->addWhere(Where::null($boolean, $column, false)),
                '<>', '!=' => $this->addWhere(Where::null($boolean, $column, true)),
                default => throw new InvalidQueryException('null can only be compared with "=" or "<>"; use whereNull() for clarity.'),
            };
        }

        return $this->addWhere(Where::basic($boolean, $column, $operator, $normalized));
    }

    private function addWhere(Where $where): self
    {
        $clone = clone $this;
        $clone->wheres[] = $where;

        return $clone;
    }

    private function addJoin(string $type, string $table, string $first, string $operator, string $second): self
    {
        Identifier::assert($table);
        $clone = clone $this;
        $clone->joins[] = ['type' => $type, 'table' => $table, 'first' => $this->column($first), 'operator' => Operator::normalize($operator), 'second' => $this->column($second)];

        return $clone;
    }

    private function column(string $column): string
    {
        Identifier::assertColumn($column);

        return $column;
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<scalar|null>
     */
    private function values(array $values): array
    {
        return array_values(array_map(Value::normalize(...), $values));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return list<array<string, scalar|null>>
     */
    private function rows(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $rows = isset($values[0]) && \is_array($values[0]) && array_is_list($values) ? $values : [$values];
        $normalized = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new InvalidQueryException('Each row must be an array of column => value.');
            }

            $clean = [];

            foreach ($row as $column => $value) {
                $clean[(string) $column] = Value::normalize($value);
            }

            $normalized[] = $clean;
        }

        return $normalized;
    }

    private function aggregate(string $function, string $column): mixed
    {
        if ($column !== '*') {
            $this->column($column);
        }

        $state = new QueryState($this->table, ['*'], false, $this->wheres, $this->joins, $this->groups, $this->havings, [], null, null, [$function, $column]);
        $query = $this->connection->grammar()->compileSelect($state);

        return $this->connection->scalar($query->sql, $query->bindings);
    }

    private function numeric(mixed $value): int|float|null
    {
        return match (true) {
            $value === null => null,
            \is_int($value), \is_float($value) => $value,
            \is_string($value) && is_numeric($value) => str_contains($value, '.') ? (float) $value : (int) $value,
            default => null,
        };
    }

    private function resultKey(string $column): string
    {
        if (preg_match('/\s+as\s+(\w+)$/i', $column, $m) === 1) {
            return $m[1];
        }

        $parts = explode('.', $column);

        return end($parts);
    }

    private function assertRestricted(string $action): void
    {
        if ($this->wheres === [] && !$this->unrestricted) {
            throw new InvalidQueryException(\sprintf('Refusing to %s every row: add a where() condition, or call unrestricted() if that is really what you want.', $action));
        }
    }
}
