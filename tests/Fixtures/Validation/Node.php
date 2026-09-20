<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

final readonly class Node
{
    public function __construct(public string $name, public ?Node $child = null) {}
}
