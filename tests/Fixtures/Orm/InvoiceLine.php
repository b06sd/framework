<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

/**
 * Identified by its invoice and its line number: a composite primary key.
 */
final class InvoiceLine
{
    public function __construct(
        public int $invoiceId = 0,
        public int $lineNo = 0,
        public string $sku = '',
        public int $quantity = 1,
    ) {}
}
