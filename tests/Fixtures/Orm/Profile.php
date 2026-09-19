<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

final class Profile
{
    public function __construct(
        public private(set) ?int $id = null,
        public int $customerId = 0,
        public string $bio = '',
    ) {}
}
