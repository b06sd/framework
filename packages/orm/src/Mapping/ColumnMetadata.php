<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use BackedEnum;

/**
 * Immutable compiled description of one column.
 */
final readonly class ColumnMetadata
{
    /**
     * @param class-string<BackedEnum>|null $enum
     */
    public function __construct(
        public string $property,
        public string $column,
        public Type $type,
        public bool $nullable,
        public bool $hidden,
        public bool $filterable,
        public bool $sortable,
        public ?string $enum,
    ) {}
}
