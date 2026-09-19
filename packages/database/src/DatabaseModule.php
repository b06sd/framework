<?php

declare(strict_types=1);

namespace Trunk\Database;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\Module;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionManager;
use Trunk\Database\Connection\DatabaseHealthCheck;
use Trunk\Database\Connection\TransactionGuard;
use Trunk\Database\Migration\MigrationRepository;
use Trunk\Database\Migration\Migrator;
use Trunk\Database\Schema\Schema;

/**
 * Registers the database layer. Reads `database.*` from config/database.php (published by
 * `trunk package:install database`). Inject `Connection` (the default connection), `Schema`, or
 * `ConnectionManager` for named connections.
 *
 * @api
 */
final class DatabaseModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(ConnectionManager::class, ConnectionManager::class, [
            new ConfigValue('database.default', 'string'),
            new ConfigValue('database.connections', 'array'),
            new ConfigValue('database.log_queries', 'bool'),
        ]);
        $builder->factory(Connection::class, [ConnectionManager::class, 'connection']);
        $builder->service(TransactionGuard::class, TransactionGuard::class, [new Reference(ConnectionManager::class)]);
        $builder->tag('trunk.lifecycle', TransactionGuard::class);
        $builder->service(DatabaseHealthCheck::class, DatabaseHealthCheck::class, [new Reference(ConnectionManager::class)]);
        $builder->tag('trunk.health_check', DatabaseHealthCheck::class);
        $builder->service(Schema::class, Schema::class, [new Reference(Connection::class)]);
        $builder->service(MigrationRepository::class, MigrationRepository::class, [new Reference(Connection::class), new Reference(Schema::class)]);
        $builder->service(Migrator::class, Migrator::class, [
            new Reference(Connection::class),
            new Reference(Schema::class),
            new Reference(MigrationRepository::class),
            new ConfigValue('database.migrations', 'string'),
        ]);
    }

    public function boot(ContainerInterface $container): void {}
}
