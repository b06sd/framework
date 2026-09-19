<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class OrderMap implements EntityMap
{
    public function entity(): string
    {
        return Order::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('orders');
        $map->id();
        $map->int('customerId');
        $map->float('total')->filterable()->sortable();
        $map->string('note')->nullable();
        $map->belongsTo('customer', Customer::class, foreignKey: 'customerId');
        $map->belongsToMany('tags', Tag::class, pivotTable: 'order_tag', pivotLocalColumn: 'order_id', pivotForeignColumn: 'tag_id');
    }
}
