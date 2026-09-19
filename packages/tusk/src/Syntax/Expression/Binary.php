<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class Binary implements Expression
{
    public function __construct(public string $operator, public Expression $left, public Expression $right) {}
}
