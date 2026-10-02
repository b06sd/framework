<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class InvoiceMap implements EntityMap
{
    public function entity(): string
    {
        return Invoice::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('invoices');
        $map->id();
        $map->string('number');
        $map->hasMany('lines', InvoiceLine::class, foreignKey: 'invoiceId');
    }
}
