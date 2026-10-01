<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use BcMath\Number;

final class StockItem
{
    public function __construct(
        public private(set) ?int $id = null,
        public string $sku = '',
        public Number $price = new Number('0'),
        public Number $onHand = new Number('0'),
        public ?Number $cost = null,
    ) {}
}
