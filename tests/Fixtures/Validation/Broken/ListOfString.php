<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation\Broken;

use stdClass;
use Trunk\Validation\Attribute\ListOf;

final class ListOfString
{
    public function __construct(#[ListOf(stdClass::class)] public string $x) {}
}
