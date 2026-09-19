<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use LogicException;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\ConnectionManager;
use Trunk\Database\Connection\TransactionGuard;

final class TransactionGuardTest extends TestCase
{
    public function test_a_transaction_left_open_is_rolled_back_and_reported(): void
    {
        // Arrange
        $manager = new ConnectionManager('main', ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $connection = $manager->connection();
        $connection->execute('CREATE TABLE t (a INTEGER)');
        $connection->beginTransaction();
        $connection->beginTransaction();
        $connection->table('t')->insert(['a' => 1]);

        // Act
        try {
            new TransactionGuard($manager)->reset();
            self::fail('Expected the guard to report the open transaction.');
        } catch (LogicException $e) {
            // Assert
            self::assertStringContainsString('2 open transaction level(s)', $e->getMessage());
        }

        self::assertSame(0, $connection->transactionDepth());
        self::assertSame(0, $connection->table('t')->count());
    }

    public function test_a_clean_unit_of_work_passes_silently_and_unopened_connections_are_left_alone(): void
    {
        // Arrange
        $manager = new ConnectionManager('main', ['main' => ['driver' => 'sqlite', 'database' => ':memory:'], 'other' => ['driver' => 'sqlite', 'database' => ':memory:']]);
        $manager->connection()->transaction(static fn() => 1);

        // Act
        new TransactionGuard($manager)->reset();

        // Assert
        self::assertCount(1, $manager->open());
    }
}
