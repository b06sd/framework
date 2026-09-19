<?php

declare(strict_types=1);

namespace Trunk\Queue;

use Trunk\Contracts\Clock;
use Trunk\Logging\ContextHolder;
use Trunk\Queue\Driver\QueueDriver;
use Trunk\Queue\Exception\QueueException;
use Trunk\Queue\Exception\UnknownJob;
use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobDefinition;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Job\Origin;
use Trunk\Queue\Job\PayloadCodec;

/**
 * Puts jobs on the queue. Inject it: there is no static access.
 *
 *   $queue->dispatch(new SendWelcomeEmail(7));
 *   $queue->dispatch(new SendWelcomeEmail(7), delay: 60, queue: 'mail');
 *
 * With the database driver the job is stored through the application's own connection, so a job
 * dispatched inside a database transaction is committed or rolled back together with your data.
 *
 * @api
 */
final readonly class Queue
{
    private const int MAX_DELAY = 31_536_000;
    /** @internal wired by the container, not part of the API */

    public function __construct(
        private QueueDriver $driver,
        private JobRegistry $registry,
        private PayloadCodec $codec,
        private Clock $clock,
        private ?ContextHolder $contexts = null,
    ) {}

    /**
     * @param int         $delay seconds before the job may run
     * @param string|null $queue overrides the queue named in the job's options()
     *
     * @return int|string the job id
     *
     * @throws QueueException
     */
    public function dispatch(Job $job, int $delay = 0, ?string $queue = null): int|string
    {
        $definition = $this->definition($job);
        $now = $this->clock->now();

        return $this->driver->push($this->queueFor($definition, $queue), $definition->metadata()->name, $this->payload($definition, $job), $now + $this->delay($delay), $now, Origin::encode($this->contexts?->get()));
    }

    /**
     * Stores many jobs with as few statements as the driver allows.
     *
     * @param list<Job> $jobs
     *
     * @return int how many were stored
     */
    public function dispatchMany(array $jobs, int $delay = 0, ?string $queue = null): int
    {
        $now = $this->clock->now();
        $rows = [];

        foreach ($jobs as $job) {
            $definition = $this->definition($job);
            $rows[] = ['queue' => $this->queueFor($definition, $queue), 'job' => $definition->metadata()->name, 'payload' => $this->payload($definition, $job), 'availableAt' => $now + $this->delay($delay), 'origin' => Origin::encode($this->contexts?->get())];
        }

        return $this->driver->pushMany($rows, $now);
    }

    public function size(string $queue = 'default'): int
    {
        return $this->driver->size($queue);
    }

    private function definition(Job $job): JobDefinition
    {
        try {
            return $this->registry->definition($job::class);
        } catch (UnknownJob) {
            throw new QueueException(\sprintf('%s is not a registered job. Put the class in app/Jobs (or list it in queue.jobs) and run `trunk build`.', $job::class));
        }
    }

    private function payload(JobDefinition $definition, Job $job): string
    {
        return $this->codec->encode($definition->encode($job), $definition->metadata()->name);
    }

    private function queueFor(JobDefinition $definition, ?string $queue): string
    {
        $name = $queue ?? $definition->metadata()->options->queue;

        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $name) !== 1) {
            throw new QueueException('The queue name must be 1 to 64 characters of a-z, 0-9, dot, dash or underscore.');
        }

        return $name;
    }

    private function delay(int $delay): int
    {
        if ($delay < 0 || $delay > self::MAX_DELAY) {
            throw new QueueException('The delay must be between 0 and one year, in seconds.');
        }

        return $delay;
    }
}
