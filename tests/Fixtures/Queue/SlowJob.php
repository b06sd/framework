<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobOptions;

final readonly class SlowJob implements Job
{
    public function handle(): void
    {
        $until = microtime(true) + 10;

        while (microtime(true) < $until) {
            usleep(1000);
        }
    }

    public static function options(): JobOptions
    {
        return new JobOptions(tries: 1, timeout: 1);
    }
}
