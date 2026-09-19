<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Di;

final readonly class Widget
{
    public function __construct(public string $label) {}
}
