<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

/**
 * StockItem on a trunk_* table, for the live MySQL/PostgreSQL suites.
 */
final class LiveStockItemMap implements EntityMap
{
    public function entity(): string
    {
        return StockItem::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('trunk_live_stock');
        $map->id();
        $map->string('sku');
        $map->decimal('price', 2);
        $map->decimal('onHand', 3);
        $map->decimal('cost', 4)->nullable();
    }
}
