<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation\Broken;

use Trunk\Validation\Rules\Pattern;

final class BadPattern
{
    public function __construct(#[Pattern("/(/")] public string $x) {}
}
