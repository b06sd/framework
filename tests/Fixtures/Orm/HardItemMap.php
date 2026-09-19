<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

/**
 * A table named trunk_hard_items, created and dropped by the live-database hardening test only.
 */
final class HardItemMap implements EntityMap
{
    public function entity(): string
    {
        return HardItem::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('trunk_hard_items');
        $map->id();
        $map->string('label')->filterable()->sortable();
        $map->int('qty')->filterable()->sortable();
        $map->bool('flag')->filterable();
        $map->float('price')->filterable()->sortable();
    }
}
