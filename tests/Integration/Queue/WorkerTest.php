<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Queue;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Queue\Exception\QueueException;
use Trunk\Queue\Worker\Outcome;
use Trunk\Queue\Worker\WorkerOptions;
use Trunk\Tests\Fixtures\Queue\FailingJob;
use Trunk\Tests\Fixtures\Queue\Plan;
use Trunk\Tests\Fixtures\Queue\ScopeProbeJob;
use Trunk\Tests\Fixtures\Queue\SlowJob;
use Trunk\Tests\Fixtures\Queue\UnrecoverableFailingJob;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;
use Trunk\Tests\Support\QueueHarness;

final class WorkerTest extends TestCase
{
    public function test_a_dispatched_job_is_decoded_run_with_its_dependencies_and_removed(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new WelcomeJob(7, Plan::Pro, 'hello'));

        // Act
        $outcome = $h->worker->processNext(['mail']);

        // Assert
        self::assertSame(Outcome::Succeeded, $outcome);
        self::assertSame(['welcome:7:pro:hello'], $h->recorder->events);
        self::assertSame(0, $h->queue->size('mail'));
        self::assertNull($h->worker->processNext(['mail']));
    }

    public function test_a_failing_job_is_retried_with_backoff_then_moved_to_the_failed_table(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new FailingJob());
        $outcomes = [];

        // Act
        $outcomes[] = $h->worker->processNext(['default']);
        $immediately = $h->worker->processNext(['default']);
        $h->clock->advance(10);
        $outcomes[] = $h->worker->processNext(['default']);
        $h->clock->advance(59);
        $notYet = $h->worker->processNext(['default']);
        $h->clock->advance(1);
        $outcomes[] = $h->worker->processNext(['default']);

        // Assert
        self::assertSame([Outcome::Retried, Outcome::Retried, Outcome::Failed], $outcomes);
        self::assertNull($immediately, 'The first backoff is 10 seconds.');
        self::assertNull($notYet, 'The second backoff is 60 seconds.');
        self::assertSame(['failing', 'failing', 'failing'], $h->recorder->events);
        $failed = $h->driver->failed();
        self::assertCount(1, $failed);
        self::assertSame(RuntimeException::class, $failed[0]->exception);
        self::assertSame(0, $h->queue->size('default'));
    }

    public function test_failure_messages_and_payloads_are_never_stored_unless_opted_in(): void
    {
        // Arrange
        $quiet = new QueueHarness();
        $verbose = new QueueHarness(null, [FailingJob::class], true);

        foreach ([$quiet, $verbose] as $h) {
            $h->queue->dispatch(new FailingJob('card-4111-1111-1111-1111'), queue: 'default');
            for ($i = 0; $i < 3; ++$i) {
                $h->worker->processNext(['default']);
                $h->clock->advance(100);
            }
        }

        // Act
        $quietFailed = $quiet->driver->failed()[0];
        $verboseFailed = $verbose->driver->failed()[0];

        // Assert
        self::assertNull($quietFailed->message);
        self::assertStringContainsString('boom', (string) $verboseFailed->message);
        self::assertStringNotContainsString('4111', serialize($quietFailed));
    }

    public function test_unrecoverable_unknown_and_undecodable_jobs_skip_the_retries(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new UnrecoverableFailingJob());
        $h->driver->push('default', 'Evil\\Unknown', '{}', 0, $h->clock->now());
        $h->driver->push('default', WelcomeJob::class, '{"userId":"seven"}', 0, $h->clock->now());
        $h->driver->push('default', WelcomeJob::class, 'O:8:"stdClass":0:{}', 0, $h->clock->now());
        $outcomes = [];

        // Act
        while (($outcome = $h->worker->processNext(['default'])) !== null) {
            $outcomes[] = $outcome;
        }

        // Assert
        self::assertSame([Outcome::Failed, Outcome::Failed, Outcome::Failed, Outcome::Failed], $outcomes);
        self::assertSame([], $h->recorder->events, 'No poisoned payload ever reached a handler.');
        self::assertSame([\Trunk\Queue\Exception\UnrecoverableJob::class, \Trunk\Queue\Exception\UnknownJob::class, \Trunk\Queue\Exception\InvalidPayload::class, \Trunk\Queue\Exception\InvalidPayload::class], array_map(static fn($f): string => $f->exception, array_reverse($h->driver->failed())));
    }

    public function test_every_job_gets_a_fresh_container_scope(): void
    {
        // Arrange
        $h = new QueueHarness(null, [ScopeProbeJob::class], false, null, 600, true);
        $h->queue->dispatchMany([new ScopeProbeJob(), new ScopeProbeJob(), new ScopeProbeJob()]);

        // Act
        $report = $h->worker->run(new WorkerOptions(stopWhenEmpty: true));

        // Assert
        self::assertSame(3, $report->succeeded);
        self::assertCount(3, $h->ledger->entries);
        self::assertCount(3, array_unique($h->ledger->entries), 'Three different scoped instances, so no state leaks between jobs.');
    }

    public function test_run_honours_once_max_jobs_and_stop_when_empty_and_sleeps_when_idle(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatchMany([new WelcomeJob(1), new WelcomeJob(2), new WelcomeJob(3)]);

        // Act
        $once = $h->worker->run(new WorkerOptions(['mail'], once: true));
        $limited = $h->worker->run(new WorkerOptions(['mail'], maxJobs: 1));
        $empty = $h->worker->run(new WorkerOptions(['mail'], stopWhenEmpty: true));
        $idle = $h->worker->run(new WorkerOptions(['mail'], maxTime: 10, sleep: 4));

        // Assert
        self::assertSame(['once', 'max-jobs', 'empty', 'max-time'], [$once->stoppedBecause, $limited->stoppedBecause, $empty->stoppedBecause, $idle->stoppedBecause]);
        self::assertSame([1, 1, 1], [$once->succeeded, $limited->succeeded, $empty->succeeded]);
        self::assertSame(0, $idle->processed());
    }

    public function test_a_stop_request_lets_the_current_job_finish_then_exits(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatchMany([new WelcomeJob(1), new WelcomeJob(2)]);
        $h->worker->stop();

        // Act
        $report = $h->worker->run(new WorkerOptions(['mail']));

        // Assert
        self::assertSame('signal', $report->stoppedBecause);
        self::assertSame(2, $h->queue->size('mail'));
    }

    public function test_a_hung_job_is_interrupted_at_its_timeout_and_counted_as_a_failure(): void
    {
        // Arrange
        if (!\function_exists('pcntl_alarm')) {
            self::markTestSkipped('ext-pcntl is required for job timeouts.');
        }

        $h = new QueueHarness();
        $h->queue->dispatch(new SlowJob());
        $started = microtime(true);

        // Act
        $outcome = $h->worker->processNext(['default']);

        // Assert
        self::assertSame(Outcome::Failed, $outcome, 'SlowJob has tries: 1.');
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertSame(\Trunk\Queue\Exception\JobTimedOut::class, $h->driver->failed()[0]->exception);
    }

    public function test_a_worker_that_dies_mid_job_loses_the_job_to_another_after_the_visibility_timeout(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new WelcomeJob(9));
        $crashed = $h->driver->reserve(['mail'], $h->clock->now(), 600, 'dead-worker');
        self::assertNotNull($crashed);

        // Act
        $early = $h->worker->processNext(['mail']);
        $h->clock->advance(600);
        $late = $h->worker->processNext(['mail']);

        // Assert
        self::assertNull($early);
        self::assertSame(Outcome::Succeeded, $late);
        self::assertSame(['welcome:9:free:-'], $h->recorder->events);
    }

    public function test_a_job_timeout_longer_than_the_visibility_window_stops_the_worker_from_starting(): void
    {
        // Arrange
        $h = new QueueHarness(null, [WelcomeJob::class], false, null, 40);

        // Act & Assert
        $this->expectException(QueueException::class);
        $this->expectExceptionMessage('could be claimed twice');
        $h->worker->run(new WorkerOptions(['mail'], stopWhenEmpty: true));
    }

    public function test_dispatch_rejects_unregistered_jobs_bad_queue_names_and_bad_delays(): void
    {
        // Arrange
        $h = new QueueHarness(null, [WelcomeJob::class]);
        $rejected = 0;

        // Act
        foreach ([
            static fn() => $h->queue->dispatch(new FailingJob()),
            static fn() => $h->queue->dispatch(new WelcomeJob(1), queue: "x'; DROP TABLE t; --"),
            static fn() => $h->queue->dispatch(new WelcomeJob(1), queue: ''),
            static fn() => $h->queue->dispatch(new WelcomeJob(1), delay: -1),
            static fn() => $h->queue->dispatch(new WelcomeJob(1), delay: 999_999_999),
            static fn() => $h->queue->dispatch(new WelcomeJob(1, note: str_repeat('x', 70000))),
        ] as $attempt) {
            try {
                $attempt();
            } catch (QueueException|\Trunk\Queue\Exception\InvalidPayload) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(6, $rejected);
        self::assertSame(0, $h->queue->size('mail'));
    }

    public function test_logs_never_contain_payload_data(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new FailingJob('card-4111-1111-1111-1111'));
        $h->queue->dispatch(new WelcomeJob(5, note: 'private-note'));

        // Act
        $h->worker->processNext(['default']);
        $h->worker->processNext(['mail']);
        $logged = json_encode(array_map(static fn(array $r): array => [$r[0], $r[1], array_keys($r[2])], $h->logger->records), JSON_THROW_ON_ERROR);

        // Assert
        self::assertStringNotContainsString('4111', $logged);
        self::assertStringNotContainsString('private-note', $logged);
        self::assertNotEmpty($h->logger->records);
    }

    public function test_each_job_runs_with_its_own_context_and_everything_is_reset_afterwards_even_on_failure(): void
    {
        // Arrange
        $h = new QueueHarness();
        $first = $h->queue->dispatch(new \Trunk\Tests\Fixtures\Queue\ContextProbeJob());
        $h->queue->dispatch(new FailingJob());

        // Act
        $h->worker->processNext(['default']);
        $afterFirst = [$h->resets->count, $h->holder->get()];
        $h->worker->processNext(['default']);

        // Assert
        self::assertSame(['job:job_' . $first], $h->recorder->events === [] ? [] : [$h->recorder->events[0]]);
        self::assertSame([1, null], $afterFirst, 'reset ran and the context was cleared');
        self::assertSame(2, $h->resets->count, 'the failing job was cleaned up too');
        self::assertNull($h->holder->get());
    }

    public function test_the_worker_stops_cleanly_when_it_reaches_its_memory_limit_and_samples_memory_on_the_gc_interval(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatchMany([new WelcomeJob(1), new WelcomeJob(2), new WelcomeJob(3)]);

        // Act
        $limited = $h->worker->run(new WorkerOptions(['mail'], memoryMb: 1));
        $sampled = new QueueHarness();
        $sampled->queue->dispatchMany([new WelcomeJob(1), new WelcomeJob(2), new WelcomeJob(3), new WelcomeJob(4)]);
        $sampled->worker->run(new WorkerOptions(['mail'], stopWhenEmpty: true, gcInterval: 2));
        $samples = array_values(array_filter($sampled->logger->records, static fn(array $r): bool => $r[1] === 'Worker memory sample.'));

        // Assert
        self::assertSame(['memory', 1], [$limited->stoppedBecause, $limited->succeeded], 'the current job finished, then the worker stopped');
        self::assertSame(2, $h->queue->size('mail'));
        self::assertCount(2, $samples);
        self::assertSame(['units', 'usage_mb', 'baseline_mb', 'peak_mb', 'gc_collected'], array_keys($samples[0][2]));
    }

    public function test_a_job_that_returns_with_a_transaction_open_is_a_failed_attempt_never_a_success(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new \Trunk\Tests\Fixtures\Queue\LeavesTransactionOpenJob());

        // Act
        $first = $h->worker->processNext(['default']);
        $h->clock->advance(10);
        $second = $h->worker->processNext(['default']);
        $h->clock->advance(60);
        $third = $h->worker->processNext(['default']);
        $failed = $h->driver->failed();

        // Assert
        self::assertSame([Outcome::Retried, Outcome::Retried, Outcome::Failed], [$first, $second, $third]);
        self::assertSame(\Trunk\Queue\Exception\TransactionLeftOpen::class, $failed[0]->exception);
        self::assertSame(0, $h->connections->connection()->transactionDepth(), 'nothing is left open for the next job');
    }

    public function test_the_request_that_queued_a_job_is_carried_to_the_worker_and_the_trace_continues(): void
    {
        // Arrange
        $h = new QueueHarness();
        $trace = 'aaaabbbbccccddddeeeeffff00001111';
        $h->holder->set(new \Trunk\Logging\RequestContext('req_ORIGIN00000000000001', $trace, 'span', 'http'));
        $h->queue->dispatch(new \Trunk\Tests\Fixtures\Queue\ContextProbeJob());
        $h->holder->reset();

        // Act
        $h->worker->processNext(['default']);

        // Assert
        self::assertSame('trace:' . $trace . ' origin:req_ORIGIN00000000000001', $h->recorder->events[1]);
        self::assertStringStartsWith('job:job_', $h->recorder->events[0]);
    }

    public function test_a_tampered_origin_is_dropped_and_the_job_starts_its_own_trace(): void
    {
        // Arrange
        $h = new QueueHarness();
        $bad = ['not json', '{"requestId":"x\\nERROR forged","traceId":"aaaabbbbccccddddeeeeffff00001111"}', '{"requestId":"req_OK000000000001","traceId":"NOTHEX"}', '{"requestId":"req_OK000000000001"}', str_repeat('a', 600), '[]', '{"requestId":["array"],"traceId":1}'];

        foreach ($bad as $origin) {
            $h->driver->push('default', \Trunk\Tests\Fixtures\Queue\ContextProbeJob::class, '{}', 0, $h->clock->now(), $origin);
        }

        // Act
        for ($i = 0; $i < \count($bad); ++$i) {
            $h->worker->processNext(['default']);
        }

        // Assert
        $origins = array_values(array_filter($h->recorder->events, static fn(string $e): bool => str_starts_with($e, 'trace:')));
        self::assertCount(\count($bad), $origins);
        foreach ($origins as $line) {
            self::assertStringEndsWith('origin:-', $line);
            self::assertStringNotContainsString('aaaabbbb', $line);
        }
    }

    public function test_the_worker_counts_outcomes_times_jobs_and_records_a_span_per_job_without_payload_data(): void
    {
        // Arrange
        $h = new QueueHarness();
        $h->queue->dispatch(new WelcomeJob(1, note: 'private-note'));
        $h->queue->dispatch(new FailingJob('card-4111'));

        // Act
        $h->worker->processNext(['mail']);
        $h->worker->processNext(['default']);
        $series = $h->metrics->snapshot();
        $names = array_map(static fn(\Trunk\Telemetry\MetricSeries $s): string => $s->name . json_encode($s->labels), array_values($series));
        $spans = array_values(array_filter($h->logger->records, static fn(array $r): bool => $r[1] === 'span'));

        // Assert
        self::assertContains('queue_jobs_total{"outcome":"succeeded"}', $names);
        self::assertContains('queue_jobs_total{"outcome":"retried"}', $names);
        self::assertContains('queue_job_duration_seconds{"outcome":"succeeded"}', $names);
        self::assertCount(2, $spans);
        $attributes = \is_array($spans[0][2]['attributes'] ?? null) ? $spans[0][2]['attributes'] : [];
        self::assertSame(['queue.job', 'succeeded'], [$spans[0][2]['name'], $attributes['outcome'] ?? null]);
        self::assertStringNotContainsString('private-note', json_encode($h->logger->records, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('card-4111', json_encode($spans, JSON_THROW_ON_ERROR));
    }
}
