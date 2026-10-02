<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

final class Invoice
{
    public function __construct(
        public private(set) ?int $id = null,
        public string $number = '',
    ) {}
}
