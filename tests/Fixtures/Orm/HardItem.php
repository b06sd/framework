<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

final class HardItem
{
    public function __construct(
        public private(set) ?int $id = null,
        public string $label = '',
        public int $qty = 0,
        public bool $flag = false,
        public float $price = 0.0,
    ) {}
}
