<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

use Trunk\Tusk\Syntax\Expression\Expression;

final readonly class OutputNode implements Node
{
    public function __construct(public Expression $expression, public bool $raw = false, public int $line = 0) {}
}
