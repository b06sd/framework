<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

final readonly class TextNode implements Node
{
    public function __construct(public string $text) {}
}
