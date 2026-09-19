<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

final class Tag
{
    public function __construct(
        public private(set) ?int $id = null,
        public string $label = '',
    ) {}
}
