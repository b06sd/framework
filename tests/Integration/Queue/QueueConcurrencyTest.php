<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Database\Migration\Migration;
use Trunk\Database\Schema\Blueprint;
use Trunk\Database\Schema\Schema;
use Trunk\Queue\Driver\DatabaseDriver;
use Trunk\Queue\Driver\QueueTables;
use Trunk\Queue\Job\DevelopmentJobRegistry;
use Trunk\Queue\Job\PayloadCodec;
use Trunk\Queue\Queue;
use Trunk\Support\SystemClock;
use Trunk\Tests\Fixtures\Queue\ConcurrencyJob;

/**
 * Several real worker processes drain one queue; every job must run exactly once. Always runs on a
 * SQLite file; runs on MySQL and PostgreSQL when TRUNK_TEST_MYSQL_* / TRUNK_TEST_PGSQL_* are set
 * (HOST, DATABASE, USER, PASSWORD, optional PORT). It only creates and drops tables named trunk_qt_*.
 */
final class QueueConcurrencyTest extends TestCase
{
    private const int JOBS = 240;

    private const int WORKERS = 4;

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('databases')]
    public function test_several_worker_processes_run_every_job_exactly_once(string $driver): void
    {
        // Arrange
        $file = sys_get_temp_dir() . '/trunk-qt-' . bin2hex(random_bytes(4)) . '.sqlite';
        $config = $this->config($driver, $file);

        if ($config === null) {
            self::markTestSkipped('Set TRUNK_TEST_' . strtoupper($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_HOST and _DATABASE to run this suite.');
        }

        $connection = new ConnectionFactory()->make('setup', $config);
        $this->tables($connection, true);

        try {
            $queue = new Queue(new DatabaseDriver($connection, 'trunk_qt_jobs', 'trunk_qt_failed'), new DevelopmentJobRegistry([ConcurrencyJob::class]), new PayloadCodec(), new SystemClock());
            $jobs = [];
            for ($i = 1; $i <= self::JOBS; ++$i) {
                $jobs[] = new ConcurrencyJob($i);
            }
            $queue->dispatchMany($jobs);

            // Act
            $processes = [];
            $pipes = [];
            for ($w = 0; $w < self::WORKERS; ++$w) {
                $process = proc_open([\PHP_BINARY, __DIR__ . '/../../Support/queue_worker.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$w], null, [...getenv(), 'TRUNK_QT_CONFIG' => json_encode($config, JSON_THROW_ON_ERROR)]);
                self::assertIsResource($process);
                $processes[$w] = $process;
            }

            $reports = [];
            foreach ($processes as $w => $process) {
                $output = (string) stream_get_contents($pipes[$w][1]);
                $errors = (string) stream_get_contents($pipes[$w][2]);
                proc_close($process);
                self::assertSame('', $errors, 'Worker ' . $w . ' wrote errors: ' . $errors);
                $reports[] = json_decode($output, true, 4, JSON_THROW_ON_ERROR);
            }

            // Assert
            $runs = array_map(static fn(mixed $v): int => is_numeric($v) ? (int) $v : 0, $connection->table('trunk_qt_runs')->pluck('job_number'));
            $workersUsed = \count(array_unique(array_map(static fn(mixed $v): string => \is_string($v) ? $v : '', $connection->table('trunk_qt_runs')->pluck('worker'))));
            self::assertCount(self::JOBS, $runs, 'Every job ran.');
            self::assertCount(self::JOBS, array_unique($runs), 'No job ran twice.');
            self::assertSame(0, $connection->table('trunk_qt_jobs')->count(), 'The queue is drained.');
            self::assertSame(0, $connection->table('trunk_qt_failed')->count());
            self::assertGreaterThanOrEqual(2, $workersUsed, 'The work was shared between processes.');
            self::assertSame(self::JOBS, $this->total($reports, 'succeeded'));
            self::assertSame(0, $this->total($reports, 'lost'));
        } finally {
            $this->tables($connection, false);

            foreach (glob($file . '*') ?: [] as $leftover) {
                unlink($leftover);
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function config(string $driver, string $file): ?array
    {
        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => $file];
        }

        $prefix = 'TRUNK_TEST_' . ($driver === 'mysql' ? 'MYSQL' : 'PGSQL') . '_';
        $host = getenv($prefix . 'HOST');
        $database = getenv($prefix . 'DATABASE');

        if ($host === false || $database === false) {
            return null;
        }

        return ['driver' => $driver, 'host' => $host, 'database' => $database, 'username' => getenv($prefix . 'USER') ?: '', 'password' => getenv($prefix . 'PASSWORD') ?: '', 'port' => (int) (getenv($prefix . 'PORT') ?: ($driver === 'mysql' ? 3306 : 5432))];
    }

    private function tables(Connection $connection, bool $create): void
    {
        $schema = new Schema($connection);

        foreach (['trunk_qt_runs', 'trunk_qt_failed', 'trunk_qt_jobs'] as $table) {
            $schema->dropIfExists($table);
        }

        if (!$create) {
            return;
        }

        $file = sys_get_temp_dir() . '/trunk-qt-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, QueueTables::migration('trunk_qt_jobs', 'trunk_qt_failed'));
        $migration = require $file;
        unlink($file);
        self::assertInstanceOf(Migration::class, $migration);
        ($migration->up)($schema);
        $schema->create('trunk_qt_runs', function (Blueprint $t): void {
            $t->id();
            $t->integer('job_number');
            $t->string('worker', 32);
        });
    }

    /**
     * @param list<mixed> $reports
     */
    private function total(array $reports, string $key): int
    {
        $total = 0;

        foreach ($reports as $report) {
            $value = \is_array($report) ? ($report[$key] ?? null) : null;
            $total += \is_int($value) ? $value : 0;
        }

        return $total;
    }
}
