<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class StockItemMap implements EntityMap
{
    public function entity(): string
    {
        return StockItem::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('stock_items');
        $map->id();
        $map->string('sku');
        $map->decimal('price', 2)->filterable();
        $map->decimal('onHand', 3);
        $map->decimal('cost', 4)->nullable();
    }
}
