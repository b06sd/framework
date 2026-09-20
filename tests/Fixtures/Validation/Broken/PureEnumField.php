<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation\Broken;

enum PureEnum
{
    case A;
}

final class PureEnumField
{
    public function __construct(public PureEnum $x) {}
}
