<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class InvoiceLineMap implements EntityMap
{
    public function entity(): string
    {
        return InvoiceLine::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('invoice_lines');
        $map->int('invoiceId');
        $map->int('lineNo');
        $map->key('invoiceId', 'lineNo');
        $map->string('sku');
        $map->int('quantity');
        $map->belongsTo('invoice', Invoice::class, foreignKey: 'invoiceId');
    }
}
