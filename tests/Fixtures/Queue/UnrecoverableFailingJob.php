<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use Trunk\Queue\Exception\UnrecoverableJob;
use Trunk\Queue\Job\Job;

final readonly class UnrecoverableFailingJob implements Job
{
    public function handle(): void
    {
        throw new UnrecoverableJob('never retry');
    }
}
