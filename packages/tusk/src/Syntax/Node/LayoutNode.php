<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

/**
 * `<layout name="app">`: wraps the page and supplies content for the layout's slots.
 */
final readonly class LayoutNode implements Node
{
    /**
     * @param list<Node>                $default content for the unnamed slot
     * @param array<string, list<Node>> $fills   content for named slots (`<fill slot="x">`)
     */
    public function __construct(public string $template, public array $default, public array $fills) {}
}
