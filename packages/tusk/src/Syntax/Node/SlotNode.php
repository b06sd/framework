<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

final readonly class SlotNode implements Node
{
    /**
     * @param list<Node> $fallback
     */
    public function __construct(public string $name, public array $fallback, public int $line = 0) {}
}
