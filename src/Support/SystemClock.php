<?php

declare(strict_types=1);

namespace Trunk\Support;

use Trunk\Contracts\Clock;

/**
 * The real time.
 */
final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
