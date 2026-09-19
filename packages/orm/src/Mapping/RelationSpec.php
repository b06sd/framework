<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

/**
 * A relation as written in a map (keys are property names; the factory resolves them to columns).
 *
 * @api
 */
final readonly class RelationSpec
{
    /**
     * @param class-string $target
     */
    public function __construct(
        public string $name,
        public RelationKind $kind,
        public string $target,
        public ?string $foreignProperty,
        public ?string $localProperty,
        public ?string $pivotTable = null,
        public ?string $pivotLocalColumn = null,
        public ?string $pivotForeignColumn = null,
    ) {}
}
