<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

use Trunk\Tusk\Syntax\Expression\Expression;

final readonly class IncludeNode implements Node
{
    /**
     * @param array<string, Expression> $with variables passed to the included template
     */
    public function __construct(public string $name, public array $with, public int $line = 0) {}
}
