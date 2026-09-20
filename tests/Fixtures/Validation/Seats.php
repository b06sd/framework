<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

final readonly class Seats
{
    public function __construct(#[Even(above: 2)] public int $count) {}
}
