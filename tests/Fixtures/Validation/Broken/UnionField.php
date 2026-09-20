<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation\Broken;

final class UnionField
{
    public function __construct(public int|string $x) {}
}
