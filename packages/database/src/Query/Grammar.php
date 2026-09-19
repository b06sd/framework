<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

use Trunk\Database\Exception\InvalidQueryException;

/**
 * Turns query state into SQL with positional `?` placeholders. Identifiers are validated and quoted
 * here; values never appear in the SQL text. Subclasses supply the dialect (quoting, LIMIT syntax).
 *
 * @internal an implementation detail, never a base class for application code
 */
abstract class Grammar
{
    /** @var array<string, string> identifier => wrapped form; the same few names are wrapped on every query */
    private array $wrapped = [];
    /**
     * Whether INSERT ... RETURNING is available for fetching a generated id.
     */
    public function supportsReturning(): bool
    {
        return false;
    }

    public function wrap(string $identifier): string
    {
        if (isset($this->wrapped[$identifier])) {
            return $this->wrapped[$identifier];
        }

        Identifier::assert($identifier);

        if (preg_match('/^(.+?)\s+as\s+(.+)$/Di', $identifier, $m) === 1) {
            $wrapped = $this->wrap($m[1]) . ' AS ' . $this->quoteSegment($m[2]);
        } else {
            $wrapped = implode('.', array_map(fn(string $s): string => $s === '*' ? '*' : $this->quoteSegment($s), explode('.', $identifier)));
        }

        // Only validated identifiers are remembered, and only a bounded number, so a stream of distinct
        // names can never grow this without limit.
        if (\count($this->wrapped) < 4096) {
            $this->wrapped[$identifier] = $wrapped;
        }

        return $wrapped;
    }

    public function compileSelect(QueryState $query): CompiledQuery
    {
        $bindings = [];
        $columns = $this->compileColumns($query, $bindings);
        $sql = 'SELECT ' . ($query->distinct && $query->aggregate === null ? 'DISTINCT ' : '') . $columns . ' FROM ' . $this->wrap($query->table);

        foreach ($query->joins as $join) {
            $sql .= ' ' . $join['type'] . ' JOIN ' . $this->wrap($join['table']) . ' ON ' . $this->wrap($join['first']) . ' ' . $join['operator'] . ' ' . $this->wrap($join['second']);
        }

        if ($query->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres($query->wheres, $bindings);
        }

        if ($query->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', array_map($this->wrap(...), $query->groups));
        }

        if ($query->havings !== []) {
            $sql .= ' HAVING ' . $this->compileWheres($query->havings, $bindings);
        }

        if ($query->aggregate === null) {
            if ($query->orders !== []) {
                $sql .= ' ORDER BY ' . implode(', ', array_map(fn(array $order): string => $this->compileOrder($order, $bindings), $query->orders));
            }

            $sql .= $this->compileLimitOffset($query->limit, $query->offset);
        }

        return new CompiledQuery($sql, $bindings);
    }

    public function compileExists(QueryState $query): CompiledQuery
    {
        $inner = $this->compileSelect(new QueryState($query->table, [new Raw('1')], false, $query->wheres, $query->joins, $query->groups, $query->havings, [], 1));

        return new CompiledQuery('SELECT EXISTS(' . $inner->sql . ') AS ' . $this->quoteSegment('exists'), $inner->bindings);
    }

    /**
     * @param list<array<string, scalar|null>> $rows every row must have the same columns
     */
    public function compileInsert(string $table, array $rows, bool $returning = false, string $idColumn = 'id'): CompiledQuery
    {
        $sql = 'INSERT INTO ' . $this->wrap($table);

        if ($rows === [] || $rows[0] === []) {
            return new CompiledQuery($sql . $this->emptyInsert() . ($returning ? ' RETURNING ' . $this->quoteSegment($idColumn) : ''), []);
        }

        $columns = array_keys($rows[0]);
        $bindings = [];
        $groups = [];

        foreach ($columns as $column) {
            Identifier::assertSimple((string) $column);
        }

        foreach ($rows as $row) {
            if (array_keys($row) !== $columns) {
                throw new InvalidQueryException('Every row in a batch insert must have the same columns in the same order.');
            }

            foreach ($row as $value) {
                $bindings[] = Value::normalize($value);
            }

            $groups[] = '(' . implode(', ', array_fill(0, \count($columns), '?')) . ')';
        }

        $sql .= ' (' . implode(', ', array_map(fn(string|int $c): string => $this->quoteSegment((string) $c), $columns)) . ') VALUES ' . implode(', ', $groups);

        return new CompiledQuery($sql . ($returning ? ' RETURNING ' . $this->quoteSegment($idColumn) : ''), $bindings);
    }

    /**
     * @param array<string, scalar|Raw|null> $values
     */
    public function compileUpdate(QueryState $query, array $values): CompiledQuery
    {
        if ($values === []) {
            throw new InvalidQueryException('An update needs at least one column.');
        }

        $bindings = [];
        $sets = [];

        foreach ($values as $column => $value) {
            Identifier::assertSimple($column);

            if ($value instanceof Raw) {
                $sets[] = $this->quoteSegment($column) . ' = ' . $value->sql;
                array_push($bindings, ...$value->bindings);
            } else {
                $sets[] = $this->quoteSegment($column) . ' = ?';
                $bindings[] = Value::normalize($value);
            }
        }

        $sql = 'UPDATE ' . $this->wrap($query->table) . ' SET ' . implode(', ', $sets);

        if ($query->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres($query->wheres, $bindings);
        }

        return new CompiledQuery($sql, $bindings);
    }

    public function compileDelete(QueryState $query): CompiledQuery
    {
        $bindings = [];
        $sql = 'DELETE FROM ' . $this->wrap($query->table);

        if ($query->wheres !== []) {
            $sql .= ' WHERE ' . $this->compileWheres($query->wheres, $bindings);
        }

        return new CompiledQuery($sql, $bindings);
    }
    abstract protected function quoteSegment(string $segment): string;

    abstract protected function compileLimitOffset(?int $limit, ?int $offset): string;

    protected function emptyInsert(): string
    {
        return ' DEFAULT VALUES';
    }

    /**
     * @param list<scalar|null> $bindings
     */
    private function compileColumns(QueryState $query, array &$bindings): string
    {
        if ($query->aggregate !== null) {
            [$function, $column] = $query->aggregate;

            return $function . '(' . ($column === '*' ? '*' : $this->wrap($column)) . ') AS ' . $this->quoteSegment('aggregate');
        }

        $columns = [];

        foreach ($query->columns as $column) {
            if ($column instanceof Raw) {
                $columns[] = $column->sql;
                array_push($bindings, ...$column->bindings);
            } else {
                $columns[] = $this->wrap($column);
            }
        }

        return implode(', ', $columns);
    }

    /**
     * @param array{string|Raw, string} $order
     * @param list<scalar|null>         $bindings
     */
    private function compileOrder(array $order, array &$bindings): string
    {
        [$column, $direction] = $order;

        if ($column instanceof Raw) {
            array_push($bindings, ...$column->bindings);

            return $column->sql;
        }

        return $this->wrap($column) . ' ' . $direction;
    }

    /**
     * @param list<Where>       $wheres
     * @param list<scalar|null> $bindings
     */
    private function compileWheres(array $wheres, array &$bindings): string
    {
        $sql = '';

        foreach ($wheres as $index => $where) {
            $sql .= ($index === 0 ? '' : ' ' . $where->boolean . ' ') . $this->compileWhere($where, $bindings);
        }

        return $sql;
    }

    /**
     * @param list<scalar|null> $bindings
     */
    private function compileWhere(Where $where, array &$bindings): string
    {
        switch ($where->type) {
            case 'basic':
                $bindings[] = $where->values[0];
                $like = str_contains($where->operator, 'LIKE') ? " ESCAPE '!'" : '';

                return $this->wrap($where->column) . ' ' . $where->operator . ' ?' . $like;
            case 'column':
                return $this->wrap($where->column) . ' ' . $where->operator . ' ' . $this->wrap($where->other);
            case 'null':
                return $this->wrap($where->column) . ' ' . $where->operator . ' NULL';
            case 'in':
                if ($where->values === []) {
                    return $where->operator === 'IN' ? '0 = 1' : '1 = 1';
                }

                array_push($bindings, ...$where->values);

                return $this->wrap($where->column) . ' ' . $where->operator . ' (' . implode(', ', array_fill(0, \count($where->values), '?')) . ')';
            case 'between':
                array_push($bindings, ...$where->values);

                return $this->wrap($where->column) . ' ' . $where->operator . ' ? AND ?';
            case 'nested':
                return '(' . $this->compileWheres($where->nested, $bindings) . ')';
            case 'raw':
                $raw = $where->raw ?? throw new InvalidQueryException('A raw condition needs SQL.');
                array_push($bindings, ...$raw->bindings);

                return '(' . $raw->sql . ')';
            default:
                throw new InvalidQueryException('Unknown condition type.');
        }
    }
}
