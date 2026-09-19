<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Tests\Support\DatabaseHarness;

final class TransactionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new DatabaseHarness()->sqlite();
        $this->connection->execute('CREATE TABLE items (name TEXT)');
    }

    public function test_a_successful_transaction_commits_and_returns_the_result(): void
    {
        // Arrange

        // Act
        $result = $this->connection->transaction(function (Connection $c): string {
            $c->table('items')->insert(['name' => 'a']);

            return 'done';
        });

        // Assert
        self::assertSame('done', $result);
        self::assertSame(['a'], $this->names());
        self::assertFalse($this->connection->inTransaction());
    }

    public function test_any_throwable_rolls_everything_back_and_is_rethrown(): void
    {
        // Arrange

        // Act
        try {
            $this->connection->transaction(function (Connection $c): void {
                $c->table('items')->insert(['name' => 'a']);

                if ($c->inTransaction()) {
                    throw new RuntimeException('boom');
                }
            });
        } catch (RuntimeException $e) {
            // Assert
            self::assertSame('boom', $e->getMessage());
            self::assertSame([], $this->names());
            self::assertSame(0, $this->connection->transactionDepth());
        }
    }

    public function test_nested_transactions_use_savepoints_so_an_inner_failure_keeps_the_outer_work(): void
    {
        // Arrange

        // Act
        $this->connection->transaction(function (Connection $outer): void {
            $outer->table('items')->insert(['name' => 'outer']);

            try {
                $outer->transaction(function (Connection $inner): void {
                    $inner->table('items')->insert(['name' => 'inner']);

                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {
                // handled: only the inner savepoint is rolled back
            }

            $outer->transaction(fn(Connection $c) => $c->table('items')->insert(['name' => 'second']));
        });

        // Assert
        self::assertSame(['outer', 'second'], $this->names());
    }

    public function test_an_outer_failure_undoes_committed_inner_savepoints(): void
    {
        // Arrange

        // Act
        try {
            $this->connection->transaction(function (Connection $outer): void {
                $outer->transaction(fn(Connection $c) => $c->table('items')->insert(['name' => 'inner']));

                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {
        }

        // Assert
        self::assertSame([], $this->names());
    }

    public function test_manual_transactions_are_depth_tracked_and_misuse_is_an_error(): void
    {
        // Arrange
        $connection = $this->connection;

        // Act
        $connection->beginTransaction();
        $connection->beginTransaction();
        $depth = $connection->transactionDepth();
        $connection->rollBack();
        $connection->commit();

        // Assert
        self::assertSame(2, $depth);
        self::assertFalse($connection->inTransaction());
        $this->expectException(InvalidQueryException::class);
        $this->expectExceptionMessage('no open transaction');
        $connection->commit();
    }

    public function test_a_failing_statement_inside_a_transaction_rolls_back_earlier_writes(): void
    {
        // Arrange
        $this->connection->execute('CREATE TABLE uniq (v TEXT UNIQUE)');

        // Act
        try {
            $this->connection->transaction(function (Connection $c): void {
                $c->table('items')->insert(['name' => 'kept?']);
                $c->table('uniq')->insert(['v' => 'x']);
                $c->table('uniq')->insert(['v' => 'x']);
            });
        } catch (\Trunk\Database\Exception\QueryException) {
        }

        // Assert
        self::assertSame([], $this->names());
        self::assertSame(0, $this->connection->table('uniq')->count());
    }

    /**
     * @return list<mixed>
     */
    private function names(): array
    {
        return array_values($this->connection->table('items')->orderBy('name')->pluck('name'));
    }
}
