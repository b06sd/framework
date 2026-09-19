<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

use Trunk\Tusk\Syntax\Expression\Expression;

final readonly class IfNode implements Node
{
    /**
     * @param non-empty-list<array{Expression, list<Node>}> $branches condition and body, for `if` then each `elseif`
     * @param list<Node>|null                               $else
     */
    public function __construct(public array $branches, public ?array $else, public int $line = 0) {}
}
