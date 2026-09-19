<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class FilterCall implements Expression
{
    /**
     * @param list<Expression> $arguments
     */
    public function __construct(public string $filter, public Expression $subject, public array $arguments = []) {}
}
