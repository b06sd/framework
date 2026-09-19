<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Queue;

use DateTimeImmutable;
use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobOptions;

final readonly class WelcomeJob implements Job
{
    /**
     * @param array<array-key, mixed> $tags
     */
    public function __construct(
        public int $userId,
        public Plan $plan = Plan::Free,
        public ?string $note = null,
        public float $ratio = 1.0,
        public bool $flag = false,
        public array $tags = [],
        public ?DateTimeImmutable $at = null,
    ) {}

    public function handle(Recorder $recorder): void
    {
        $recorder->events[] = 'welcome:' . $this->userId . ':' . $this->plan->value . ':' . ($this->note ?? '-');
    }

    public static function options(): JobOptions
    {
        return new JobOptions(tries: 4, backoff: [5, 30], timeout: 20, queue: 'mail');
    }
}
