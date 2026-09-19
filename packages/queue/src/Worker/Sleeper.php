<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

interface Sleeper
{
    public function sleep(int $seconds): void;
}
