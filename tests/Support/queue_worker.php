<?php

declare(strict_types=1);

// Run by QueueConcurrencyTest as a separate process: one real worker draining the shared queue.
require __DIR__ . '/../../vendor/autoload.php';

use Trunk\Container\ContainerBuilder;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionFactory;
use Trunk\Queue\Driver\DatabaseDriver;
use Trunk\Queue\Job\DevelopmentJobRegistry;
use Trunk\Queue\Job\PayloadCodec;
use Trunk\Queue\Worker\Worker;
use Trunk\Queue\Worker\WorkerOptions;
use Trunk\Tests\Fixtures\Queue\ConcurrencyJob;

$decoded = json_decode((string) getenv('TRUNK_QT_CONFIG'), true, 8, JSON_THROW_ON_ERROR);
$config = is_array($decoded) ? array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY) : [];
$connection = new ConnectionFactory()->make('worker', $config);
$builder = new ContainerBuilder();
$builder->instance(Connection::class, $connection);
$worker = new Worker(
    driver: new DatabaseDriver($connection, 'trunk_qt_jobs', 'trunk_qt_failed'),
    registry: new DevelopmentJobRegistry([ConcurrencyJob::class]),
    codec: new PayloadCodec(),
    container: $builder->build(),
);
$report = $worker->run(new WorkerOptions(['default'], stopWhenEmpty: true));
echo json_encode(['succeeded' => $report->succeeded, 'failed' => $report->failed, 'lost' => $report->lost]);
