<?php

declare(strict_types=1);

namespace Trunk\Queue\Worker;

use Psr\Log\LoggerInterface;
use Throwable;
use Trunk\Container\Scopable;
use Trunk\Contracts\Clock;
use Trunk\Database\Connection\TransactionGuard;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Lifecycle\MemoryMonitor;
use Trunk\Lifecycle\MemoryPolicy;
use Trunk\Lifecycle\MemoryVerdict;
use Trunk\Logging\ContextHolder;
use Trunk\Logging\RequestContext;
use Trunk\Logging\TraceParent;
use Trunk\Observability\Metrics;
use Trunk\Observability\Tracer;
use Trunk\Queue\Driver\QueueDriver;
use Trunk\Queue\Driver\ReservedJob;
use Trunk\Queue\Exception\PermanentFailure;
use Trunk\Queue\Exception\QueueException;
use Trunk\Queue\Exception\TransactionLeftOpen;
use Trunk\Queue\Job\JobOptions;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Job\Origin;
use Trunk\Queue\Job\PayloadCodec;
use Trunk\Support\SystemClock;

/**
 * Runs jobs one at a time. Each job gets a fresh container scope, so scoped services (a unit of
 * work, a request-like context) never leak between jobs; singletons are shared for the life of
 * the process, which is why long-running workers are recycled with --max-jobs/--max-time/--memory.
 *
 * Failure handling: a job that fails is retried after its backoff until it runs out of tries,
 * then moved to the failed table. Failures that retrying cannot fix (an unknown job, an
 * undecodable payload, UnrecoverableJob) skip the retries. The attempt is counted when the job is
 * claimed, so a job that kills its worker still runs out of tries.
 */
final class Worker
{
    private const int TIMEOUT_GRACE = 30;

    private bool $stopping = false;

    public function __construct(
        private readonly QueueDriver $driver,
        private readonly JobRegistry $registry,
        private readonly PayloadCodec $codec,
        private readonly Scopable $container,
        private readonly Clock $clock = new SystemClock(),
        private readonly Sleeper $sleeper = new SystemSleeper(),
        private readonly Signals $signals = new Signals(),
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $storeFailureMessages = false,
        private readonly int $visibilityTimeout = 600,
        private readonly ?LifecycleManager $lifecycle = null,
        private readonly ?ContextHolder $contexts = null,
        private readonly ?TransactionGuard $transactions = null,
        private readonly ?Metrics $metrics = null,
        private readonly ?Tracer $tracer = null,
    ) {}

    public function stop(): void
    {
        $this->stopping = true;
    }

    public function run(WorkerOptions $options): WorkerReport
    {
        $this->assertTimeoutsFit();
        $this->signals->onStop($this->stop(...));
        $worker = $this->workerId();
        $started = $this->clock->now();
        $counts = ['succeeded' => 0, 'retried' => 0, 'failed' => 0, 'lost' => 0];
        $processed = 0;
        $reason = 'stopped';
        $memory = new MemoryMonitor(new MemoryPolicy($options->memoryMb * 1024 * 1024, $options->gcInterval, $options->growthWarnMb * 1024 * 1024));

        while (true) {
            if ($this->stopping) {
                $reason = 'signal';

                break;
            }

            if ($options->maxJobs > 0 && $processed >= $options->maxJobs) {
                $reason = 'max-jobs';

                break;
            }

            if ($options->maxTime > 0 && $this->clock->now() - $started >= $options->maxTime) {
                $reason = 'max-time';

                break;
            }

            $outcome = $this->processNext($options->queues, $worker);

            if ($outcome === null) {
                if ($options->once || $options->stopWhenEmpty) {
                    $reason = 'empty';

                    break;
                }

                $this->sleeper->sleep($options->sleep);

                continue;
            }

            ++$processed;
            ++$counts[$outcome->value];
            $verdict = $memory->afterUnit();

            if ($options->gcInterval > 0 && $memory->units() % $options->gcInterval === 0) {
                $this->logger?->debug('Worker memory sample.', $memory->stats());
            }

            if ($verdict === MemoryVerdict::Growing) {
                $this->logger?->warning('Worker memory keeps growing; check for state kept between jobs (or use --max-jobs to recycle the worker).', $memory->stats());
            }

            if ($verdict === MemoryVerdict::Restart) {
                $this->logger?->warning('Worker reached its memory limit and will stop so it can be restarted.', $memory->stats());
                $reason = 'memory';

                break;
            }

            if ($options->once) {
                $reason = 'once';

                break;
            }
        }

        return new WorkerReport($counts['succeeded'], $counts['retried'], $counts['failed'], $counts['lost'], $reason);
    }

    /**
     * Claims and runs one job. Returns null when nothing is available.
     *
     * @param list<string> $queues
     */
    public function processNext(array $queues, string $worker = 'worker'): ?Outcome
    {
        $job = $this->driver->reserve($queues, $this->clock->now(), $this->visibilityTimeout, $worker);

        if ($job === null) {
            return null;
        }

        $origin = Origin::parse($job->origin);
        $this->contexts?->set(new RequestContext('job_' . $job->id, $origin['traceId'] ?? TraceParent::newTraceId(), TraceParent::newSpanId(), 'job', $origin['requestId'] ?? null));

        $span = $this->tracer?->startSpan('queue.job', ['job' => $job->job, 'queue' => $job->queue, 'attempt' => $job->attempts]);
        $started = hrtime(true);
        $outcome = null;

        try {
            return $outcome = $this->runJob($job);
        } finally {
            $this->observe($job, $outcome, (hrtime(true) - $started) / 1e9);
            $span?->setAttribute('outcome', $outcome->value ?? 'error');
            $span?->end();
            $this->lifecycle?->cleanup();
            $this->contexts?->reset();
        }
    }

    private function observe(ReservedJob $job, ?Outcome $outcome, float $seconds): void
    {
        try {
            $labels = ['outcome' => $outcome->value ?? 'error'];
            $this->metrics?->counter('queue_jobs_total', $labels);
            $this->metrics?->observe('queue_job_duration_seconds', $seconds, $labels);
        } catch (Throwable) {
            // Metrics must never change a job's outcome.
        }
    }

    private function runJob(ReservedJob $job): Outcome
    {
        $options = new JobOptions();

        try {
            $definition = $this->registry->definition($job->job);
            $options = $definition->metadata()->options;
            $instance = $definition->decode($this->codec->parse($job->payload, $job->job));
            $this->signals->withTimeout($options->timeout, fn() => $definition->invoke($this->container->beginScope(), $instance));
            $left = $this->transactions?->rollBackOpen() ?? 0;

            if ($left > 0) {
                throw TransactionLeftOpen::levels($left);
            }
        } catch (Throwable $e) {
            return $this->failed($job, $options, $e);
        }

        if (!$this->driver->complete($job)) {
            $this->logger?->warning('Job finished after it was reclaimed by another worker.', ['job' => $job->job, 'id' => $job->id]);

            return Outcome::Lost;
        }

        $this->logger?->info('Job succeeded.', ['job' => $job->job, 'id' => $job->id, 'attempt' => $job->attempts]);

        return Outcome::Succeeded;
    }

    private function failed(ReservedJob $job, JobOptions $options, Throwable $e): Outcome
    {
        $context = ['job' => $job->job, 'id' => $job->id, 'attempt' => $job->attempts, 'exception' => $e];

        if (!$e instanceof PermanentFailure && $job->attempts < $options->tries) {
            $released = $this->driver->release($job, $this->clock->now() + $options->delayAfter($job->attempts));
            $this->logger?->warning('Job failed and will be retried.', $context);

            return $released ? Outcome::Retried : Outcome::Lost;
        }

        $this->driver->fail($job, $e::class, $this->storeFailureMessages ? $e->getMessage() : null, $this->clock->now());
        $this->logger?->error('Job failed permanently.', $context);

        return Outcome::Failed;
    }

    /**
     * A job whose timeout is longer than the visibility window would be handed to a second worker
     * while the first is still running it. Refuse to start rather than run jobs twice.
     */
    private function assertTimeoutsFit(): void
    {
        $tooLong = [];

        foreach ($this->registry->names() as $name) {
            $timeout = $this->registry->definition($name)->metadata()->options->timeout;

            if ($timeout + self::TIMEOUT_GRACE > $this->visibilityTimeout) {
                $tooLong[] = $name;
            }
        }

        if ($tooLong !== []) {
            throw new QueueException(\sprintf('queue.visibility_timeout (%d s) must exceed every job timeout by at least %d s, or a running job could be claimed twice. Too long: %s.', $this->visibilityTimeout, self::TIMEOUT_GRACE, implode(', ', $tooLong)));
        }
    }

    private function workerId(): string
    {
        return substr(\sprintf('%s-%d-%s', substr((string) gethostname(), 0, 30), getmypid(), bin2hex(random_bytes(3))), 0, 64);
    }
}
