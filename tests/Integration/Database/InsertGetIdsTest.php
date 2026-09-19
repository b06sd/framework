<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Tests\Support\DatabaseHarness;

final class InsertGetIdsTest extends TestCase
{
    public function test_ids_come_back_in_row_order_across_statement_chunks(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n INTEGER)');
        $rows = [];
        for ($i = 1; $i <= 40000; ++$i) {
            $rows[] = ['n' => $i];
        }

        // Act
        $ids = $connection->table('t')->insertGetIds($rows);

        // Assert
        self::assertCount(40000, $ids);
        self::assertSame(range(1, 40000), $ids);
        self::assertSame(40000, $connection->table('t')->count());
        self::assertSame(40000, $connection->table('t')->where('id', 40000)->value('n'));
        self::assertSame([], $connection->table('t')->insertGetIds([]));
    }

    public function test_the_ids_match_their_rows_after_earlier_deletes(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $connection->execute('CREATE TABLE t (id INTEGER PRIMARY KEY AUTOINCREMENT, n INTEGER)');
        $connection->table('t')->insert([['n' => 0], ['n' => 0]]);
        $connection->table('t')->where('n', 0)->delete();

        // Act
        $ids = $connection->table('t')->insertGetIds([['n' => 10], ['n' => 20], ['n' => 30]]);

        // Assert
        self::assertSame([10, 20, 30], array_map(fn(int $id) => $connection->table('t')->where('id', $id)->value('n'), $ids));
    }

    public function test_a_non_integer_generated_id_is_refused_rather_than_guessed(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $connection->execute('CREATE TABLE t (id TEXT PRIMARY KEY DEFAULT (lower(hex(randomblob(4)))), n INTEGER)');

        // Act & Assert
        $this->expectException(InvalidQueryException::class);
        $connection->table('t')->insertGetIds([['n' => 1], ['n' => 2]]);
    }

    public function test_a_bad_id_column_name_is_rejected(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();

        // Act & Assert
        $this->expectException(InvalidQueryException::class);
        $connection->table('t')->insertGetIds([['n' => 1]], 'id; DROP TABLE t');
    }
}
