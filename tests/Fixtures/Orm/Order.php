<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

final class Order
{
    public function __construct(
        public private(set) ?int $id = null,
        public int $customerId = 0,
        public float $total = 0.0,
        public ?string $note = null,
    ) {}
}
