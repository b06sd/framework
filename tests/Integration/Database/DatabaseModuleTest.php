<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Trunk\Container\ContainerBuilder;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Connection\ConnectionManager;
use Trunk\Database\DatabaseModule;
use Trunk\Database\Migration\Migrator;
use Trunk\Database\Schema\Schema;
use Trunk\Tests\Support\ContainerModes;

final class DatabaseModuleTest extends TestCase
{
    public function test_the_database_services_behave_the_same_in_development_and_compiled_containers(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static fn(ContainerBuilder $b) => new DatabaseModule()->register($b),
            ['database' => [
                'default' => 'main',
                'log_queries' => true,
                'migrations' => sys_get_temp_dir() . '/trunk-no-such-migrations',
                'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:'], 'other' => ['driver' => 'sqlite', 'database' => ':memory:']],
            ]],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $connection = $container->get(Connection::class);
            $schema = $container->get(Schema::class);
            $migrator = $container->get(Migrator::class);
            $manager = $container->get(ConnectionManager::class);

            // Assert
            self::assertInstanceOf(Connection::class, $connection, $mode);
            self::assertInstanceOf(Schema::class, $schema, $mode);
            self::assertInstanceOf(Migrator::class, $migrator, $mode);
            self::assertInstanceOf(ConnectionManager::class, $manager, $mode);
            $connection->execute('CREATE TABLE t (a INTEGER)');
            $connection->table('t')->insert(['a' => 1]);
            self::assertSame($connection, $container->get(Connection::class), $mode);
            self::assertSame(1, $connection->table('t')->count(), $mode);
            self::assertSame([], $migrator->files(), $mode);
            self::assertNotSame($connection, $manager->connection('other'), $mode);
        }
    }
}
