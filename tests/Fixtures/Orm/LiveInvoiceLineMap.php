<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

/**
 * InvoiceLine on a trunk_* table, for the live MySQL/PostgreSQL suites.
 */
final class LiveInvoiceLineMap implements EntityMap
{
    public function entity(): string
    {
        return InvoiceLine::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('trunk_live_lines');
        $map->int('invoiceId');
        $map->int('lineNo');
        $map->key('invoiceId', 'lineNo');
        $map->string('sku');
        $map->int('quantity');
    }
}
