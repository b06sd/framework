<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class Variable implements Expression
{
    public function __construct(public string $name) {}
}
