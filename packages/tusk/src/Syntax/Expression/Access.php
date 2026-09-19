<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Expression;

final readonly class Access implements Expression
{
    public function __construct(public Expression $base, public Expression $key) {}
}
