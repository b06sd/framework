<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class CustomerMap implements EntityMap
{
    public function entity(): string
    {
        return Customer::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('customers');
        $map->id();
        $map->string('name')->filterable()->sortable();
        $map->string('email')->filterable()->sortable();
        $map->string('passwordHash')->hidden();
        $map->enum('status', Status::class)->filterable();
        $map->string('nickname')->nullable();
        $map->bool('active')->filterable();
        $map->float('balance')->sortable();
        $map->json('settings');
        $map->dateTime('createdAt')->sortable();
        $map->softDeletes();
        $map->version();
        $map->hasMany('orders', Order::class, foreignKey: 'customerId');
        $map->hasOne('profile', Profile::class, foreignKey: 'customerId');
    }
}
