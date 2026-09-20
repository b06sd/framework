<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation\Broken;

final class IterableField
{
    public function __construct(public iterable $x) {}
}
