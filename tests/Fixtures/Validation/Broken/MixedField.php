<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation\Broken;

final class MixedField
{
    public function __construct(public mixed $x) {}
}
