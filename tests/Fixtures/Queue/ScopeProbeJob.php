<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use Trunk\Queue\Job\Job;

final readonly class ScopeProbeJob implements Job
{
    public function handle(Recorder $scoped, Ledger $ledger): void
    {
        $ledger->entries[] = spl_object_id($scoped);
        $scoped->events[] = 'dirty';
    }
}
