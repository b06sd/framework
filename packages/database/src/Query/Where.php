<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

/**
 * One condition of a WHERE (or HAVING) clause. Values are kept apart from the SQL text and are
 * only ever bound.
 */
final readonly class Where
{
    /**
     * @param list<scalar|null> $values
     * @param list<Where>       $nested
     */
    private function __construct(
        public string $type,
        public string $boolean,
        public string $column = '',
        public string $operator = '',
        public array $values = [],
        public array $nested = [],
        public ?Raw $raw = null,
        public string $other = '',
    ) {}

    public static function basic(string $boolean, string $column, string $operator, string|int|float|bool|null $value): self
    {
        return new self('basic', $boolean, $column, $operator, [$value]);
    }

    public static function column(string $boolean, string $first, string $operator, string $second): self
    {
        return new self('column', $boolean, $first, $operator, other: $second);
    }

    public static function null(string $boolean, string $column, bool $negated): self
    {
        return new self('null', $boolean, $column, $negated ? 'IS NOT' : 'IS');
    }

    /**
     * @param list<scalar|null> $values
     */
    public static function in(string $boolean, string $column, array $values, bool $negated): self
    {
        return new self('in', $boolean, $column, $negated ? 'NOT IN' : 'IN', $values);
    }

    public static function between(string $boolean, string $column, string|int|float|bool|null $low, string|int|float|bool|null $high, bool $negated): self
    {
        return new self('between', $boolean, $column, $negated ? 'NOT BETWEEN' : 'BETWEEN', [$low, $high]);
    }

    /**
     * @param list<Where> $nested
     */
    public static function nested(string $boolean, array $nested): self
    {
        return new self('nested', $boolean, nested: $nested);
    }

    public static function raw(string $boolean, Raw $raw): self
    {
        return new self('raw', $boolean, raw: $raw);
    }
}
