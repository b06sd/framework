<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Queue;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Migration\Migration;
use Trunk\Database\Schema\Schema;
use Trunk\Queue\Driver\ArrayDriver;
use Trunk\Queue\Driver\DatabaseDriver;
use Trunk\Queue\Driver\QueueDriver;
use Trunk\Queue\Driver\QueueTables;
use Trunk\Tests\Support\DatabaseHarness;

/**
 * One contract, run against the in-memory driver and the real database driver (SQLite).
 */
final class QueueDriverTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): QueueDriver}>
     */
    public static function drivers(): iterable
    {
        yield 'array' => [static fn(): QueueDriver => new ArrayDriver()];
        yield 'database' => [static function (): QueueDriver {
            $connection = new DatabaseHarness()->sqlite();
            $file = sys_get_temp_dir() . '/trunk-queue-migration-' . bin2hex(random_bytes(4)) . '.php';
            file_put_contents($file, QueueTables::migration('trunk_jobs', 'trunk_failed_jobs'));
            $migration = require $file;
            unlink($file);
            self::assertInstanceOf(Migration::class, $migration);
            ($migration->up)(new Schema($connection));

            return new DatabaseDriver($connection);
        }];
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_jobs_are_claimed_oldest_first_and_every_claim_counts_an_attempt(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $first = $driver->push('default', 'A', '{"n":1}', 100, 100);
        $driver->push('default', 'B', '{"n":2}', 100, 100);

        // Act
        $a = $driver->reserve(['default'], 100, 60, 'w1');
        $b = $driver->reserve(['default'], 100, 60, 'w2');
        $none = $driver->reserve(['default'], 100, 60, 'w3');

        // Assert
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertSame($first, $a->id);
        self::assertSame(['A', 1, '{"n":1}', 'default'], [$a->job, $a->attempts, $a->payload, $a->queue]);
        self::assertSame('B', $b->job);
        self::assertNull($none);
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_delayed_jobs_wait_and_earlier_queues_have_priority(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $driver->push('low', 'Low', '{}', 100, 100);
        $driver->push('high', 'Later', '{}', 200, 100);

        // Act
        $before = $driver->reserve(['high'], 150, 60, 'w');
        $prioritised = $driver->reserve(['high', 'low'], 150, 60, 'w');
        $after = $driver->reserve(['high'], 200, 60, 'w');

        // Assert
        self::assertNull($before);
        self::assertSame('Low', $prioritised?->job);
        self::assertSame('Later', $after?->job);
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_an_abandoned_job_is_reclaimed_after_the_visibility_timeout_and_the_old_owner_loses_it(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $driver->push('default', 'A', '{}', 0, 0);
        $crashed = $driver->reserve(['default'], 1000, 60, 'crashed');

        // Act
        $tooEarly = $driver->reserve(['default'], 1059, 60, 'other');
        $reclaimed = $driver->reserve(['default'], 1060, 60, 'other');

        // Assert
        self::assertNotNull($crashed);
        self::assertNull($tooEarly);
        self::assertNotNull($reclaimed);
        self::assertSame(2, $reclaimed->attempts);
        self::assertFalse($driver->complete($crashed), 'The crashed worker cannot complete a job it no longer owns.');
        self::assertFalse($driver->release($crashed, 0));
        self::assertSame(1, $driver->size('default'));
        self::assertTrue($driver->complete($reclaimed));
        self::assertSame(0, $driver->size('default'));
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_release_reschedules_and_fail_moves_to_the_failed_list_until_retried(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $driver->push('default', 'A', '{"x":"payload"}', 0, 0);
        $job = $driver->reserve(['default'], 10, 60, 'w');
        self::assertNotNull($job);

        // Act
        $released = $driver->release($job, 50);
        $notYet = $driver->reserve(['default'], 49, 60, 'w');
        $again = $driver->reserve(['default'], 50, 60, 'w');
        self::assertNotNull($again);
        $driver->fail($again, 'RuntimeException', null, 60);
        $failed = $driver->failed();
        $retried = $driver->retry($failed[0]->id, 70);
        $back = $driver->reserve(['default'], 70, 60, 'w');

        // Assert
        self::assertTrue($released);
        self::assertNull($notYet);
        self::assertSame(2, $again->attempts);
        self::assertCount(1, $failed);
        self::assertSame(['default', 'A', 'RuntimeException', null, 60], [$failed[0]->queue, $failed[0]->job, $failed[0]->exception, $failed[0]->message, $failed[0]->failedAt]);
        self::assertSame(1, $retried);
        self::assertSame(1, $back?->attempts, 'A retried job starts with a fresh attempt count.');
        self::assertSame('{"x":"payload"}', $back->payload);
        self::assertSame([], $driver->failed());
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_a_stale_worker_cannot_fail_a_job_that_moved_on_and_flush_clears_the_failed_list(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $driver->push('default', 'A', '{}', 0, 0);
        $stale = $driver->reserve(['default'], 0, 10, 'stale');
        $current = $driver->reserve(['default'], 10, 10, 'current');
        self::assertNotNull($stale);
        self::assertNotNull($current);

        // Act
        $driver->fail($stale, 'X', null, 11);
        $stillQueued = $driver->size('default');
        $driver->fail($current, 'Y', 'a message', 12);
        $failed = $driver->failed();
        $driver->push('default', 'B', '{}', 0, 0);
        $driver->fail($driver->reserve(['default'], 12, 10, 'w') ?? $current, 'Z', null, 13);

        // Assert
        self::assertSame(1, $stillQueued);
        self::assertSame('a message', $failed[0]->message);
        self::assertSame(2, $driver->flushFailed());
        self::assertSame([], $driver->failed());
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_many_jobs_are_pushed_in_one_go_and_retry_all_restores_them(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $jobs = [];
        for ($i = 0; $i < 1200; ++$i) {
            $jobs[] = ['queue' => 'bulk', 'job' => 'J' . $i, 'payload' => '{"i":' . $i . '}', 'availableAt' => 0];
        }

        // Act
        $stored = $driver->pushMany($jobs, 0);
        $reserved = $driver->reserve(['bulk'], 1, 60, 'w');
        self::assertNotNull($reserved);
        $driver->fail($reserved, 'E', null, 2);
        $retried = $driver->retry(null, 3);

        // Assert
        self::assertSame(1200, $stored);
        self::assertSame(1200, $driver->size('bulk'));
        self::assertSame(1, $retried);
        self::assertSame(0, $driver->pushMany([], 0));
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_hostile_queue_names_and_payloads_are_data_not_sql(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $payload = '{"a":"\'); DROP TABLE trunk_jobs; --"}';
        $queue = "x' OR '1'='1";
        $driver->push('default', "Job'); DROP TABLE trunk_jobs; --", $payload, 0, 0);

        // Act
        $none = $driver->reserve([$queue, "'; DELETE FROM trunk_jobs; --"], 10, 60, 'w');
        $real = $driver->reserve(['default'], 10, 60, 'w');

        // Assert
        self::assertNull($none);
        self::assertNotNull($real);
        self::assertSame($payload, $real->payload);
        self::assertSame("Job'); DROP TABLE trunk_jobs; --", $real->job);
    }

    /**
     * @param Closure(): QueueDriver $make
     */
    #[DataProvider('drivers')]
    public function test_the_origin_travels_with_the_job_and_is_optional(Closure $make): void
    {
        // Arrange
        $driver = $make();
        $origin = '{"requestId":"req_1","traceId":"aaaabbbbccccddddeeeeffff00001111"}';
        $driver->push('default', 'A', '{}', 0, 0, $origin);
        $driver->push('default', 'B', '{}', 0, 0);
        $driver->pushMany([['queue' => 'default', 'job' => 'C', 'payload' => '{}', 'availableAt' => 0, 'origin' => $origin], ['queue' => 'default', 'job' => 'D', 'payload' => '{}', 'availableAt' => 0]], 0);

        // Act
        $reserved = [];
        while (($job = $driver->reserve(['default'], 1, 60, 'w')) !== null) {
            $reserved[$job->job] = $job->origin;
        }

        // Assert
        self::assertSame(['A' => $origin, 'B' => null, 'C' => $origin, 'D' => null], $reserved);
    }

    public function test_the_database_driver_refuses_table_names_that_are_not_plain_identifiers(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();

        // Act & Assert
        $this->expectException(\Trunk\Database\Exception\InvalidQueryException::class);
        new DatabaseDriver($connection, 'jobs; DROP TABLE x');
    }
}
