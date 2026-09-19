<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

final readonly class SystemSleeper implements Sleeper
{
    public function sleep(int $seconds): void
    {
        sleep($seconds);
    }
}
