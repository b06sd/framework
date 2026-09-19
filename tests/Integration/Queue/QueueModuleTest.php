<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Queue;

use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Queue\Queue;
use Trunk\Queue\QueueModule;
use Trunk\Queue\Worker\Worker;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Queue\Bad\BrokenJob;
use Trunk\Tests\Fixtures\Queue\FailingJob;
use Trunk\Tests\Fixtures\Queue\Recorder;
use Trunk\Tests\Fixtures\Queue\WelcomeJob;
use Trunk\Tests\Support\ContainerModes;
use Trunk\Tests\Support\DatabaseHarness;

final class QueueModuleTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-queue-module-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->directory);
    }

    public function test_the_same_job_flow_works_in_development_and_compiled_containers(): void
    {
        // Arrange
        $contribution = $this->plan(['mode' => 'development', 'jobs' => [WelcomeJob::class, FailingJob::class], 'visibility_timeout' => 600]);
        foreach ($contribution->writers as $write) {
            $write($this->directory);
        }
        $database = ['default' => 'main', 'log_queries' => false, 'migrations' => $this->directory, 'connections' => ['main' => ['driver' => 'sqlite', 'database' => $this->directory . '/q.sqlite']]];
        $connection = new DatabaseHarness()->sqlite();
        unset($connection);
        $results = [];

        foreach (['development', 'compiled'] as $mode) {
            $queueConfig = ['mode' => $mode, 'jobs' => [WelcomeJob::class, FailingJob::class], 'build' => $this->directory, 'connection' => 'main', 'table' => 'trunk_jobs', 'failed_table' => 'trunk_failed_jobs', 'max_payload' => 65536, 'visibility_timeout' => 600, 'store_failure_messages' => false];
            $containers = new ContainerModes()->both(
                static function (ContainerBuilder $b): void {
                    new DatabaseModule()->register($b);
                    new QueueModule()->register($b);
                    $b->singleton(Recorder::class);
                    new \Trunk\Foundation\Logging\LoggingModule()->register($b);
                    new \Trunk\Foundation\Diagnostics\DiagnosticsModule()->register($b);
                },
                ['database' => $database, 'queue' => $queueConfig, 'logging' => ['channel' => 'null']],
                [Recorder::class],
            );

            foreach ($containers as $container => $root) {
                // Act
                $scope = $root->beginScope();
                $connectionForTables = $scope->get(\Trunk\Database\Connection\ConnectionManager::class);
                self::assertInstanceOf(\Trunk\Database\Connection\ConnectionManager::class, $connectionForTables);
                $db = $connectionForTables->connection('main');
                $db->execute('DROP TABLE IF EXISTS trunk_jobs');
                $db->execute('DROP TABLE IF EXISTS trunk_failed_jobs');
                $migration = require $this->writeMigration();
                self::assertInstanceOf(\Trunk\Database\Migration\Migration::class, $migration);
                ($migration->up)(new \Trunk\Database\Schema\Schema($db));
                $queue = $scope->get(Queue::class);
                $worker = $scope->get(Worker::class);
                self::assertInstanceOf(Queue::class, $queue);
                self::assertInstanceOf(Worker::class, $worker);
                $queue->dispatch(new WelcomeJob(4, note: 'hi'));
                $outcome = $worker->processNext(['mail']);
                $recorder = $scope->get(Recorder::class);
                self::assertInstanceOf(Recorder::class, $recorder);

                // Assert
                $results[$mode . '/' . $container] = [$outcome?->value, $recorder->events];
            }
        }

        self::assertCount(1, array_unique(array_map(static fn(array $r): string => (string) json_encode($r), $results)));
        self::assertSame(['succeeded', ['welcome:4:free:hi']], reset($results));
    }

    public function test_the_build_fails_with_every_error_for_broken_jobs_and_for_timeouts_that_do_not_fit(): void
    {
        // Arrange
        $broken = fn() => $this->plan(['jobs' => [BrokenJob::class]]);
        $tooSlow = fn() => $this->plan(['jobs' => [WelcomeJob::class], 'visibility_timeout' => 40]);
        $errors = [];

        // Act
        foreach ([$broken, $tooSlow] as $plan) {
            try {
                $plan();
            } catch (CompilationException $e) {
                array_push($errors, ...$e->errors);
            }
        }

        // Assert
        self::assertStringContainsString('cannot be queued', implode("\n", $errors));
        self::assertStringContainsString('could be claimed twice', implode("\n", $errors));
        self::assertFileDoesNotExist($this->directory . '/queue.php');
    }

    public function test_the_handler_services_become_compiled_container_roots(): void
    {
        // Arrange & Act
        $contribution = $this->plan(['jobs' => [WelcomeJob::class], 'visibility_timeout' => 600]);

        // Assert
        self::assertSame([Recorder::class], $contribution->containerRoots);
    }

    public function test_production_refuses_to_run_without_a_build(): void
    {
        // Arrange
        $registry = new \Trunk\Queue\Job\ConfiguredJobRegistry(new Configuration(['queue' => ['mode' => 'compiled', 'build' => $this->directory, 'jobs' => []]]));

        // Act & Assert
        $this->expectException(\Trunk\Queue\Exception\QueueException::class);
        $this->expectExceptionMessage('trunk build');
        $registry->names();
    }

    /**
     * @param array<string, mixed> $queue
     */
    private function plan(array $queue): \Trunk\Compiler\Build\BuildContribution
    {
        return new QueueModule()->plan(new BuildContext(new ModuleManifest([]), new Runtime(Environment::Production, false, $this->directory), new Configuration(['queue' => $queue])));
    }

    private function writeMigration(): string
    {
        $file = $this->directory . '/m' . bin2hex(random_bytes(3)) . '.php';
        file_put_contents($file, \Trunk\Queue\Driver\QueueTables::migration('trunk_jobs', 'trunk_failed_jobs'));

        return $file;
    }
}
