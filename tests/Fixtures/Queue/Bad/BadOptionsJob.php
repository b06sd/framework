<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue\Bad;

use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobOptions;

final class BadOptionsJob implements Job
{
    public function handle(): void {}

    public static function options(): JobOptions
    {
        return new JobOptions(tries: 0);
    }
}
