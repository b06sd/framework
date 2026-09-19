<?php

declare(strict_types=1);

namespace Trunk\Tusk\Syntax\Node;

use Trunk\Tusk\Syntax\Expression\Expression;

final readonly class ForNode implements Node
{
    /**
     * @param list<Node>      $body
     * @param list<Node>|null $empty rendered when the collection has no items
     */
    public function __construct(
        public Expression $each,
        public string $as,
        public ?string $key,
        public array $body,
        public ?array $empty,
        public int $line = 0,
    ) {}
}
