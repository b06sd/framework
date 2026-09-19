<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Lifecycle;

use ArrayObject;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Lifecycle\LifecycleAware;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Lifecycle\MemoryMonitor;
use Trunk\Lifecycle\MemoryPolicy;
use Trunk\Lifecycle\MemoryVerdict;
use Trunk\Tests\Support\RecordingLogger;

final class MemoryAndLifecycleTest extends TestCase
{
    private const int MB = 1048576;

    public function test_sizes_are_parsed_and_bad_policies_rejected(): void
    {
        // Arrange & Act & Assert
        self::assertSame(512 * self::MB, MemoryPolicy::bytes('512M'));
        self::assertSame(2 * 1024 ** 3, MemoryPolicy::bytes('2G'));
        self::assertSame(64 * 1024, MemoryPolicy::bytes('64k'));
        self::assertSame(1000, MemoryPolicy::bytes('1000'));
        $rejected = 0;

        foreach (['', 'lots', '-5M', '1.5G', '12X', "5M\n"] as $bad) {
            try {
                MemoryPolicy::bytes($bad);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        try {
            new MemoryPolicy(window: 1);
        } catch (InvalidArgumentException) {
            ++$rejected;
        }

        self::assertSame(7, $rejected);
    }

    public function test_the_garbage_collector_runs_only_on_the_interval_never_per_unit(): void
    {
        // Arrange
        $collections = 0;
        $monitor = $this->monitor(new MemoryPolicy(gcInterval: 10), [10 * self::MB], $collections);

        // Act
        for ($i = 0; $i < 95; ++$i) {
            $monitor->afterUnit();
        }

        // Assert
        self::assertSame(9, $collections);
        self::assertSame(3, $monitor->stats()['gc_collected']);
        $never = $this->monitor(new MemoryPolicy(gcInterval: 0), [1], $collections);
        $before = $collections;
        for ($i = 0; $i < 50; ++$i) {
            $never->afterUnit();
        }
        self::assertSame($before, $collections);
    }

    public function test_a_stable_worker_is_ok_and_a_steady_climb_is_reported_once_per_episode(): void
    {
        // Arrange
        $stable = $this->monitor(new MemoryPolicy(gcInterval: 0, growthWarnBytes: 50 * self::MB, warmup: 3), array_merge(array_fill(0, 3, 24 * self::MB), array_map(static fn(int $i): int => (24 + $i % 3) * self::MB, range(1, 200))));
        $climbing = $this->monitor(new MemoryPolicy(gcInterval: 0, growthWarnBytes: 50 * self::MB, warmup: 3, window: 5), array_map(static fn(int $i): int => (25 + $i * 4) * self::MB, range(0, 40)));

        // Act
        $stableVerdicts = [];
        for ($i = 0; $i < 150; ++$i) {
            $stableVerdicts[] = $stable->afterUnit();
        }
        $climbVerdicts = [];
        for ($i = 0; $i < 40; ++$i) {
            $climbVerdicts[] = $climbing->afterUnit();
        }

        // Assert
        self::assertSame([MemoryVerdict::Ok], array_values(array_unique($stableVerdicts, SORT_REGULAR)));
        self::assertSame(1, \count(array_filter($climbVerdicts, static fn(MemoryVerdict $v): bool => $v === MemoryVerdict::Growing)), 'reported once, not on every unit');
    }

    public function test_reaching_the_limit_asks_for_a_clean_restart_even_before_the_warmup_ends(): void
    {
        // Arrange
        $monitor = $this->monitor(new MemoryPolicy(limitBytes: 100 * self::MB, warmup: 50), [40 * self::MB, 99 * self::MB, 100 * self::MB]);

        // Act
        $verdicts = [$monitor->afterUnit(), $monitor->afterUnit(), $monitor->afterUnit()];

        // Assert
        self::assertSame([MemoryVerdict::Ok, MemoryVerdict::Ok, MemoryVerdict::Restart], $verdicts);
    }

    public function test_the_diagnostic_numbers_contain_no_application_data(): void
    {
        // Arrange
        $monitor = $this->monitor(new MemoryPolicy(gcInterval: 1, warmup: 0), [30 * self::MB]);
        $monitor->afterUnit();

        // Act
        $stats = $monitor->stats();

        // Assert
        self::assertSame(['units', 'usage_mb', 'baseline_mb', 'peak_mb', 'gc_collected'], array_keys($stats));
        self::assertSame(30.0, $stats['usage_mb']);
    }

    public function test_resets_run_in_reverse_order_and_one_failure_does_not_stop_the_others(): void
    {
        // Arrange
        $order = new ArrayObject();
        $make = static fn(string $name, bool $fails = false): LifecycleAware => new class ($name, $fails, $order) implements LifecycleAware {
            /** @param ArrayObject<int, string> $order */
            public function __construct(private string $name, private bool $fails, private ArrayObject $order) {}

            public function reset(): void
            {
                $this->order[] = $this->name;

                if ($this->fails) {
                    throw new RuntimeException('reset failed');
                }
            }
        };
        $logger = new RecordingLogger();
        $manager = new LifecycleManager([$make('first'), $make('second', true), $make('third')], $logger);

        // Act
        $failed = $manager->cleanup();
        $second = $manager->cleanup();

        // Assert
        self::assertSame(['third', 'second', 'first', 'third', 'second', 'first'], $order->getArrayCopy());
        self::assertSame([1, 1], [$failed, $second]);
        self::assertSame('warning', $logger->records[0][0]);
    }

    public function test_with_nothing_registered_cleanup_is_free_and_a_failing_logger_is_contained(): void
    {
        // Arrange
        $empty = new LifecycleManager();
        $broken = new LifecycleManager([new class implements LifecycleAware {
            public function reset(): void
            {
                throw new RuntimeException('x');
            }
        }], new RecordingLogger(throws: true));

        // Act & Assert
        self::assertSame(0, $empty->cleanup());
        self::assertSame(1, $broken->cleanup());
    }

    /**
     * @param list<int> $usages bytes reported after each unit
     */
    private function monitor(MemoryPolicy $policy, array $usages, ?int &$collections = null): MemoryMonitor
    {
        $i = 0;

        return new MemoryMonitor($policy, static function () use (&$i, $usages): int {
            return $usages[min($i++, \count($usages) - 1)];
        }, static function () use (&$collections): int {
            ++$collections;

            return 3;
        });
    }
}
