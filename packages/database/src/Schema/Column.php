<?php

declare(strict_types=1);

namespace Trunk\Database\Schema;

use Trunk\Database\Query\Raw;

/**
 * A column definition. Modifier methods chain: `$table->string('email')->unique()->nullable()`.
 *
 * @api
 */
final class Column
{
    public bool $nullable = false;

    public bool $hasDefault = false;

    public string|int|float|bool|Raw|null $default = null;

    public bool $unsigned = false;

    public bool $unique = false;

    public bool $index = false;

    public bool $primary = false;

    public ?string $referencesTable = null;

    public string $referencesColumn = 'id';

    public ?string $onDelete = null;

    /**
     * @param list<int> $parameters length, or precision and scale
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly array $parameters = [],
    ) {}

    public function nullable(bool $nullable = true): self
    {
        $this->nullable = $nullable;

        return $this;
    }

    /**
     * A constant default. Use `new Raw('CURRENT_TIMESTAMP')` for an expression.
     */
    public function default(string|int|float|bool|Raw|null $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;

        return $this;
    }

    public function unsigned(): self
    {
        $this->unsigned = true;

        return $this;
    }

    public function unique(): self
    {
        $this->unique = true;

        return $this;
    }

    public function index(): self
    {
        $this->index = true;

        return $this;
    }

    public function primary(): self
    {
        $this->primary = true;

        return $this;
    }

    /**
     * Adds a foreign key to `$table.$column`.
     */
    public function constrained(string $table, string $column = 'id'): self
    {
        $this->referencesTable = $table;
        $this->referencesColumn = $column;

        return $this;
    }

    /**
     * @param 'cascade'|'restrict'|'set null'|'no action' $action
     */
    public function onDelete(string $action): self
    {
        $this->onDelete = $action;

        return $this;
    }
}
