<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Contracts\Clock;

final class FixedClock implements Clock
{
    public function __construct(public int $now = 1_000_000) {}

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
