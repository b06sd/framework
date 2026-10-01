<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Pipeline\Sink;

use PHPUnit\Framework\TestCase;
use Trunk\Pipeline\Sink\DatabaseSink;
use Trunk\Tests\Support\DatabaseHarness;

final class DatabaseSinkTest extends TestCase
{
    public function test_write_inserts_the_whole_chunk_in_one_call(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();
        $connection->execute('CREATE TABLE customers (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(255) NOT NULL)');
        $sink = new DatabaseSink($connection, 'customers');

        // Act
        $sink->write([['name' => 'Ada'], ['name' => 'Grace']]);

        // Assert
        self::assertSame(['Ada', 'Grace'], $connection->table('customers')->orderBy('id')->pluck('name'));
    }
}
