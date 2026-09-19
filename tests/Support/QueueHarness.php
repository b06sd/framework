<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;
use Trunk\Container\Container;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Clock;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionManager;
use Trunk\Database\Connection\TransactionGuard;
use Trunk\Lifecycle\LifecycleAware;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Logging\ContextHolder;
use Trunk\Queue\Driver\ArrayDriver;
use Trunk\Queue\Driver\QueueDriver;
use Trunk\Queue\Job\DevelopmentJobRegistry;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Job\PayloadCodec;
use Trunk\Queue\Queue;
use Trunk\Queue\Worker\Signals;
use Trunk\Queue\Worker\Sleeper;
use Trunk\Queue\Worker\Worker;
use Trunk\Telemetry\InMemoryMetrics;
use Trunk\Telemetry\LogTracer;
use Trunk\Tests\Fixtures\Queue\ContextProbeJob;
use Trunk\Tests\Fixtures\Queue\FailingJob;
use Trunk\Tests\Fixtures\Queue\LeavesTransactionOpenJob;
use Trunk\Tests\Fixtures\Queue\Ledger;
use Trunk\Tests\Fixtures\Queue\Recorder;
use Trunk\Tests\Fixtures\Queue\ScopeProbeJob;
use Trunk\Tests\Fixtures\Queue\SlowJob;
use Trunk\Tests\Fixtures\Queue\UnrecoverableFailingJob;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;

/**
 * A fake clock, a recording sleeper, an in-memory (or supplied) driver and a container, wired the
 * way the module wires them, so worker behaviour can be tested without waiting.
 */
final class QueueHarness
{
    public readonly TestClock $clock;

    public readonly QueueDriver $driver;

    public readonly Queue $queue;

    public readonly Worker $worker;

    public readonly Recorder $recorder;

    public readonly Ledger $ledger;

    public readonly MemoryLogger $logger;

    public readonly Container $container;

    public readonly ContextHolder $holder;

    public readonly ResetCounter $resets;

    public readonly ConnectionManager $connections;

    public readonly InMemoryMetrics $metrics;

    /**
     * @param list<string> $jobs
     */
    public function __construct(?QueueDriver $driver = null, array $jobs = [WelcomeJob::class, FailingJob::class, UnrecoverableFailingJob::class, SlowJob::class, ScopeProbeJob::class, ContextProbeJob::class, LeavesTransactionOpenJob::class], bool $storeMessages = false, ?JobRegistry $registry = null, int $visibility = 600, bool $scopedRecorder = false)
    {
        $this->clock = new TestClock(1_000_000);
        $this->driver = $driver ?? new ArrayDriver();
        $this->recorder = new Recorder();
        $this->ledger = new Ledger();
        $this->logger = new MemoryLogger();
        $registry ??= new DevelopmentJobRegistry($jobs);
        $codec = new PayloadCodec();
        $builder = new ContainerBuilder();

        if ($scopedRecorder) {
            $builder->scoped(Recorder::class);
        } else {
            $builder->instance(Recorder::class, $this->recorder);
        }

        $builder->instance(Ledger::class, $this->ledger);
        $this->holder = new ContextHolder();
        $this->resets = new ResetCounter();
        $builder->instance(ContextHolder::class, $this->holder);
        $this->connections = new ConnectionManager('main', ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $builder->instance(Connection::class, $this->connections->connection());
        $this->container = $builder->build();
        $this->metrics = new InMemoryMetrics();
        $this->queue = new Queue($this->driver, $registry, $codec, $this->clock, $this->holder);
        $this->worker = new Worker($this->driver, $registry, $codec, $this->container, $this->clock, new TestSleeper($this->clock), new Signals(), $this->logger, $storeMessages, $visibility, new LifecycleManager([$this->resets, $this->holder], $this->logger), $this->holder, new TransactionGuard($this->connections), $this->metrics, new LogTracer($this->logger, $this->holder));
    }
}

final class TestClock implements Clock
{
    public function __construct(private int $now) {}

    public function now(): int
    {
        return $this->now;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}

final class TestSleeper implements Sleeper
{
    /** @var list<int> */
    public array $slept = [];

    public function __construct(private readonly TestClock $clock) {}

    public function sleep(int $seconds): void
    {
        $this->slept[] = $seconds;
        $this->clock->advance($seconds);
    }
}

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{string, string, array<string, mixed>}> */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [\is_string($level) ? $level : 'unknown', (string) $message, $context];
    }
}

final class ResetCounter implements LifecycleAware
{
    public int $count = 0;

    public function reset(): void
    {
        ++$this->count;
    }
}
