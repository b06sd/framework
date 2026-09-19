<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Database;

use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Database\Connection\QueryLog;
use Trunk\Database\Exception\ConnectionException;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Query\Raw;
use Trunk\Tests\Support\DatabaseHarness;

final class QueryExecutionTest extends TestCase
{
    public function test_the_connection_opens_lazily_and_hides_its_configuration_when_dumped(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->sqlite();

        // Act
        $before = $connection->__debugInfo();
        $connection->scalar('SELECT 1');
        $after = $connection->__debugInfo();

        // Assert
        self::assertFalse($before['connected']);
        self::assertTrue($after['connected']);
        self::assertSame(['name', 'driver', 'connected', 'transactionDepth'], array_keys($after));
    }

    public function test_rows_come_back_with_real_types_from_native_prepared_statements(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite());

        // Act
        $row = $connection->table('users')->where('name', 'Ada')->first();

        // Assert
        self::assertSame(['id' => 1, 'name' => 'Ada', 'email' => 'ada@example.com', 'age' => 36, 'active' => 1, 'score' => 9.5], $row);
    }

    public function test_the_builder_reads_and_aggregates(): void
    {
        // Arrange
        $users = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite())->table('users');

        // Act & Assert
        self::assertSame(3, $users->count());
        self::assertSame(2, $users->where('age', '>', 18)->count());
        self::assertSame(98, $users->sum('age'));
        self::assertEqualsWithDelta(32.666, (float) $users->avg('age'), 0.01);
        self::assertSame(45, $users->max('age'));
        self::assertSame(17, $users->min('age'));
        self::assertSame(['Ada', 'Grace', 'Linus'], $users->orderBy('id')->pluck('name'));
        self::assertSame([1 => 'Ada', 2 => 'Grace', 3 => 'Linus'], $users->orderBy('id')->pluck('name', 'id'));
        self::assertSame('grace@example.com', $users->where('name', 'Grace')->value('email'));
        self::assertSame('Ada', $users->find(1)['name'] ?? null);
        self::assertNull($users->find(99));
        self::assertTrue($users->where('name', 'Ada')->exists());
        self::assertFalse($users->where('name', 'Nobody')->exists());
        self::assertSame(['Grace'], $users->forPage(2, 1)->pluck('name'));
        self::assertSame(2, \count($users->whereIn('name', ['Ada', 'Linus'])->get()));
        self::assertSame(1, \count($users->whereNull('score')->get()));
        self::assertSame(2, \count($users->whereBetween('age', 30, 50)->get()));
    }

    public function test_writes_return_affected_rows_and_ids_and_enforce_the_where_guard(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite());
        $users = $connection->table('users');

        // Act
        $id = $users->insertGetId(['name' => 'Ken', 'email' => 'ken@example.com', 'age' => 80]);
        $updated = $users->where('id', $id)->update(['age' => 81, 'score' => new Raw('age + 0.5')]);
        $deleted = $users->where('name', 'Linus')->delete();
        $batch = $users->insert([['name' => 'A', 'email' => 'a@x.dev', 'age' => 1], ['name' => 'B', 'email' => 'b@x.dev', 'age' => 2]]);

        // Assert
        self::assertSame(4, $id);
        self::assertSame(1, $updated);
        self::assertSame(81, $users->find($id)['age'] ?? null);
        self::assertSame(80.5, $users->find($id)['score'] ?? null);
        self::assertSame(1, $deleted);
        self::assertSame(2, $batch);
        self::assertSame(0, $users->insert([]));
        self::assertSame(5, $users->count());
        $this->expectException(InvalidQueryException::class);
        $users->delete();
    }

    public function test_unrestricted_updates_are_possible_when_asked_for(): void
    {
        // Arrange
        $users = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite())->table('users');

        // Act
        $affected = $users->unrestricted()->update(['age' => 1]);

        // Assert
        self::assertSame(3, $affected);
        self::assertSame(3, $users->where('age', 1)->count());
    }

    public function test_raw_sql_binds_named_and_positional_values_and_native_types_survive(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite());

        // Act
        $named = $connection->select('SELECT name FROM users WHERE age > :min AND active = :active ORDER BY id', ['min' => 30, ':active' => true]);
        $positional = $connection->select('SELECT name FROM users WHERE age < ? OR name = ?', [20, 'Ada']);
        $nullBound = $connection->execute('UPDATE users SET score = ? WHERE name = ?', [null, 'Ada']);

        // Assert
        self::assertSame([['name' => 'Ada'], ['name' => 'Grace']], $named);
        self::assertSame([['name' => 'Ada'], ['name' => 'Linus']], $positional);
        self::assertSame(1, $nullBound);
        self::assertNull($connection->table('users')->where('name', 'Ada')->value('score'));
    }

    public function test_bindings_must_be_plain_values_and_named_bindings_plain_names(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite());
        $rejected = 0;

        // Act
        foreach ([[[1, 2]], [new stdClass()], ['bad name' => 1], ['a;b' => 1]] as $bindings) {
            try {
                $connection->select('SELECT 1', $bindings);
            } catch (InvalidQueryException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
    }

    public function test_a_failed_query_reports_the_sqlstate_and_sql_but_never_the_values(): void
    {
        // Arrange
        $connection = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite());

        // Act
        try {
            $connection->table('users')->insert(['name' => 'Dup', 'email' => 'ada@example.com', 'age' => 1]);
            self::fail('Expected a QueryException.');
        } catch (QueryException $e) {
            // Assert
            self::assertStringContainsString('integrity constraint violation', $e->getMessage());
            self::assertStringNotContainsString('ada@example.com', $e->getMessage());
            self::assertStringNotContainsString('Dup', $e->getMessage());
            self::assertSame('INSERT INTO "users" ("name", "email", "age") VALUES (?, ?, ?)', $e->sql);
            self::assertSame('23000', $e->sqlState);
        }
    }

    public function test_the_query_log_records_sql_and_binding_counts_but_never_values(): void
    {
        // Arrange
        $log = new QueryLog();
        $connection = new DatabaseHarness()->sqlite($log);
        $connection->execute('CREATE TABLE t (a TEXT)');

        // Act
        $connection->table('t')->insert(['a' => 'top-secret']);
        $connection->table('t')->where('a', 'top-secret')->get();

        // Assert
        self::assertCount(3, $log->entries());
        self::assertSame(1, $log->entries()[1]['bindings']);
        self::assertStringNotContainsString('top-secret', (string) json_encode($log->entries()));
        $log->clear();
        self::assertSame(0, $log->count());
    }

    public function test_connection_errors_never_reveal_the_dsn_or_credentials(): void
    {
        // Arrange
        $connection = new \Trunk\Database\Connection\ConnectionFactory()->make('main', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app', 'username' => 'root-user', 'password' => 'hunter2-password']);

        // Act
        try {
            $connection->scalar('SELECT 1');
            self::fail('Expected a ConnectionException.');
        } catch (ConnectionException $e) {
            // Assert
            self::assertStringNotContainsString('hunter2', $e->getMessage() . var_export($connection->__debugInfo(), true));
            self::assertStringNotContainsString('root-user', $e->getMessage());
            self::assertStringContainsString('"main"', $e->getMessage());
            self::assertNull($e->getPrevious());
        }
    }

    public function test_dsn_settings_cannot_smuggle_extra_options(): void
    {
        // Arrange
        $factory = new \Trunk\Database\Connection\ConnectionFactory();
        $rejected = 0;

        // Act
        foreach ([
            ['driver' => 'mysql', 'host' => 'localhost;dbname=other', 'database' => 'app'],
            ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'app;charset=latin1'],
            ['driver' => 'pgsql', 'host' => 'a b', 'database' => 'app'],
            ['driver' => 'pgsql', 'host' => 'localhost', 'database' => 'app', 'sslmode' => 'disable;host=evil'],
            ['driver' => 'mysql', 'host' => 'localhost', 'database' => 'app', 'port' => '3306;x'],
            ['driver' => 'sqlite', 'database' => 'a.db;ATTACH'],
            ['driver' => 'sqlite', 'database' => "a\0.db"],
            ['driver' => 'oracle', 'database' => 'x'],
        ] as $config) {
            try {
                $factory->make('x', $config)->scalar('SELECT 1');
            } catch (ConnectionException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(8, $rejected);
    }
}
