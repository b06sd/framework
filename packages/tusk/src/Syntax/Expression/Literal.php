<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class Literal implements Expression
{
    public function __construct(public string|int|float|bool|null $value) {}
}
