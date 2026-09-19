<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Services;

final readonly class CycleA
{
    public function __construct(public CycleB $b) {}
}
