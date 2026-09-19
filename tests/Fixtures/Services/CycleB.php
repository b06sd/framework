<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Services;

final readonly class CycleB
{
    public function __construct(public CycleA $a) {}
}
