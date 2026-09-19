<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use RuntimeException;
use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobOptions;

final readonly class FailingJob implements Job
{
    public function __construct(public string $secret = 'card-4111-1111-1111-1111') {}

    public function handle(Recorder $recorder): void
    {
        $recorder->events[] = 'failing';

        throw new RuntimeException('boom ' . $this->secret);
    }

    public static function options(): JobOptions
    {
        return new JobOptions(tries: 3, backoff: [10, 60]);
    }
}
