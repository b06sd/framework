<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue\Bad;

use Trunk\Queue\Job\Job;

final class NoHandleJob implements Job
{
    public static function options(): string
    {
        return 'nope';
    }
}
