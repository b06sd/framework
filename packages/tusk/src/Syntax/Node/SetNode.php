<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

use Trunk\Tusk\Syntax\Expression\Expression;

final readonly class SetNode implements Node
{
    public function __construct(public string $name, public Expression $value, public int $line = 0) {}
}
