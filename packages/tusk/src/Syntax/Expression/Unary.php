<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class Unary implements Expression
{
    /**
     * @param 'not'|'-'|'+' $operator
     */
    public function __construct(public string $operator, public Expression $operand) {}
}
