<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax;

use Trunk\Tusk\Syntax\Node\LayoutNode;
use Trunk\Tusk\Syntax\Node\Node;

final readonly class Template
{
    /**
     * @param list<Node> $body all nodes, or the sole LayoutNode for a page that uses a layout
     */
    public function __construct(public ?LayoutNode $layout, public array $body) {}
}
