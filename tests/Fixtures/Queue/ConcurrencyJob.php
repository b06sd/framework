<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use Trunk\Database\Connection\Connection;
use Trunk\Queue\Job\Job;

/**
 * Records every execution in a table with no unique constraint, so a job that ran twice shows up.
 */
final readonly class ConcurrencyJob implements Job
{
    public function __construct(public int $number) {}

    public function handle(Connection $connection): void
    {
        usleep(random_int(1000, 6000));
        $connection->table('trunk_qt_runs')->insert(['job_number' => $this->number, 'worker' => (string) getmypid()]);
    }
}
