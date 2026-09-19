<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

/**
 * A relation between two entities. Keys are stored as *columns* (already resolved from the
 * properties named in the map), so the eager loader never has to look anything up.
 *
 * - HasOne / HasMany: `foreignColumn` on the target points at `localColumn` on this entity.
 * - BelongsTo: `foreignColumn` on this entity points at `localColumn` (the owner key) on the target.
 * - BelongsToMany: `pivotTable` links `pivotLocalColumn` (this entity's key) to `pivotForeignColumn` (the target's key).
 */
final readonly class RelationMetadata
{
    /**
     * @param class-string $target
     */
    public function __construct(
        public string $name,
        public RelationKind $kind,
        public string $target,
        public string $localColumn,
        public string $foreignColumn,
        public ?string $pivotTable = null,
        public ?string $pivotLocalColumn = null,
        public ?string $pivotForeignColumn = null,
    ) {}
}
