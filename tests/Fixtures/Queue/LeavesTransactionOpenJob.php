<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use Trunk\Database\Connection\Connection;
use Trunk\Queue\Job\Job;

final readonly class LeavesTransactionOpenJob implements Job
{
    public function handle(Connection $connection): void
    {
        $connection->beginTransaction();
    }
}
