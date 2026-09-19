<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Migration\Migration;
use Trunk\Database\Schema\Schema;
use Trunk\Queue\Compiler\JobArtifact;
use Trunk\Queue\Compiler\JobCodeGenerator;
use Trunk\Queue\Driver\DatabaseDriver;
use Trunk\Queue\Driver\QueueTables;
use Trunk\Queue\Job\DevelopmentJobRegistry;
use Trunk\Queue\Job\JobMetadataFactory;
use Trunk\Queue\Worker\WorkerOptions;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Queue\Plan;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;
use Trunk\Tests\Support\DatabaseHarness;
use Trunk\Tests\Support\QueueHarness;

/**
 * Query counts are hard assertions; timings are printed to stderr and only loosely bounded.
 */
final class QueuePerformanceTest extends TestCase
{
    public function test_generated_codecs_beat_the_interpreted_codec(): void
    {
        // Arrange
        $directory = sys_get_temp_dir() . '/trunk-qp-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/queue.php', new JobCodeGenerator()->generate(new JobMetadataFactory()->fromClasses([WelcomeJob::class])));
        $compiled = JobArtifact::load($directory . '/queue.php')->definition(WelcomeJob::class);
        $interpreted = new DevelopmentJobRegistry([WelcomeJob::class])->definition(WelcomeJob::class);
        $job = new WelcomeJob(7, Plan::Pro, 'note', 1.5, true, ['a' => 1], new DateTimeImmutable('2026-01-01 00:00:00 UTC'));
        $n = 20000;

        // Act
        $start = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $compiled->decode($compiled->encode($job));
        }
        $generated = (hrtime(true) - $start) / 1e9;
        $start = hrtime(true);
        for ($i = 0; $i < $n; ++$i) {
            $interpreted->decode($interpreted->encode($job));
        }
        $slow = (hrtime(true) - $start) / 1e9;
        new Directory()->remove($directory);

        // Assert
        $this->report('encode+decode (generated)', $generated, $n);
        $this->report('encode+decode (interpreted, dev)', $slow, $n);
        self::assertSame($compiled->encode($job), $interpreted->encode($job));
        self::assertLessThan($slow, $generated);
    }

    public function test_many_jobs_dispatch_in_few_statements_and_all_run(): void
    {
        // Arrange
        $connection = $this->connection();
        $h = new QueueHarness(new DatabaseDriver($connection));
        $jobs = [];
        for ($i = 0; $i < 2000; ++$i) {
            $jobs[] = new WelcomeJob($i);
        }

        // Act
        $start = hrtime(true);
        $h->queue->dispatchMany($jobs);
        $dispatch = (hrtime(true) - $start) / 1e9;
        $inserts = \count(array_filter($connection->log()?->entries() ?? [], static fn(array $e): bool => str_starts_with($e['sql'], 'INSERT')));
        $start = hrtime(true);
        $report = $h->worker->run(new WorkerOptions(['mail'], stopWhenEmpty: true));
        $work = (hrtime(true) - $start) / 1e9;

        // Assert
        $this->report('dispatchMany 2000 jobs', $dispatch, 2000);
        $this->report('claim + run + complete (sqlite)', $work, 2000);
        self::assertLessThanOrEqual(2, $inserts, '2000 single-row payloads fit in at most two multi-row INSERTs.');
        self::assertSame(2000, $report->succeeded);
        self::assertCount(2000, $h->recorder->events);
    }

    public function test_a_job_costs_one_select_one_update_and_one_delete(): void
    {
        // Arrange
        $connection = $this->connection();
        $h = new QueueHarness(new DatabaseDriver($connection));
        $h->queue->dispatch(new WelcomeJob(1));
        $connection->log()?->clear();

        // Act
        $h->worker->processNext(['mail']);
        $statements = array_map(static fn(array $e): string => strtok($e['sql'], ' ') ?: '', $connection->log()?->entries() ?? []);

        // Assert
        self::assertSame(['SELECT', 'UPDATE', 'DELETE'], $statements);
    }
    private function report(string $label, float $seconds, int $count): void
    {
        fwrite(\STDERR, \sprintf("\n[queue-perf] %-44s %8.1f ms  (%d ops, %.1f us/op)", $label, $seconds * 1000, $count, $seconds / max(1, $count) * 1e6));
    }

    private function connection(): Connection
    {
        $log = new QueryLog();
        $connection = new DatabaseHarness()->sqlite($log);
        $file = sys_get_temp_dir() . '/trunk-qp-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, QueueTables::migration('trunk_jobs', 'trunk_failed_jobs'));
        $migration = require $file;
        unlink($file);
        self::assertInstanceOf(Migration::class, $migration);
        ($migration->up)(new Schema($connection));
        $log->clear();

        return $connection;
    }
}
