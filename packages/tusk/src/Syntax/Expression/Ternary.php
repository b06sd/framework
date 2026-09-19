<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class Ternary implements Expression
{
    public function __construct(public Expression $condition, public Expression $then, public Expression $else) {}
}
