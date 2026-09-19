<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use BackedEnum;

/**
 * Fluent options for one mapped column, returned by the MapBuilder.
 *
 * @api
 */
final class ColumnSpec
{
    public bool $nullable = false;

    public bool $hidden = false;

    public bool $filterable = false;

    public bool $sortable = false;

    /**
     * @param class-string<BackedEnum>|null $enum
     */
    public function __construct(
        public readonly string $property,
        public readonly string $column,
        public readonly Type $type,
        public readonly ?string $enum = null,
    ) {}

    /** NULL is a legal value (the constructor parameter must accept null). */
    public function nullable(): static
    {
        $this->nullable = true;

        return $this;
    }

    /**
     * Never selected (unless a query says withHidden()), never in toArray()/JSON output, never
     * in dumps. The constructor parameter must have a default: a loaded entity holds that default
     * for this column, and it is only written back when you assign a different value.
     */
    public function hidden(): static
    {
        $this->hidden = true;

        return $this;
    }

    /** Request input may filter on this property (Query::filter()). */
    public function filterable(): static
    {
        $this->filterable = true;

        return $this;
    }

    /** Request input may sort by this property (Query::sortBy()). */
    public function sortable(): static
    {
        $this->sortable = true;

        return $this;
    }
}
