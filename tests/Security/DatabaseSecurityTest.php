<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Exception\QueryException;
use Trunk\Database\Query\Like;
use Trunk\Tests\Support\DatabaseHarness;

/**
 * Hostile input must always stay data: values are bound, identifiers are validated.
 */
final class DatabaseSecurityTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new DatabaseHarness()->seededUsers(new DatabaseHarness()->sqlite());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function payloads(): iterable
    {
        yield 'drop table' => ["'); DROP TABLE users; --"];
        yield 'or true' => ["' OR '1'='1"];
        yield 'union' => ["' UNION SELECT name, email, age, active, score, id FROM users --"];
        yield 'stacked' => ["x'; DELETE FROM users WHERE '1'='1"];
        yield 'comment' => ["admin'--"];
        yield 'double quote' => ['" OR ""="'];
        yield 'backslash' => ["\\'; DROP TABLE users; --"];
        yield 'null byte' => ["x\0y"];
        yield 'unicode quote' => ["\u{2019} OR 1=1"];
        yield 'like wildcard' => ['%'];
        yield 'sleep' => ["'; SELECT sqlite_version(); --"];
    }

    #[DataProvider('payloads')]
    public function test_payloads_are_stored_and_read_back_as_data_and_the_table_survives(string $payload): void
    {
        // Arrange
        $users = $this->connection->table('users');

        // Act
        $id = $users->insertGetId(['name' => $payload, 'email' => md5($payload) . '@x.dev', 'age' => 1]);
        $found = $users->where('name', $payload)->get();
        $count = $users->where('name', '=', $payload)->count();

        // Assert
        self::assertCount(1, $found);
        self::assertSame($payload, $found[0]['name']);
        self::assertSame(1, $count);
        self::assertSame(4, $users->count());
        self::assertSame($id, $found[0]['id']);
    }

    #[DataProvider('payloads')]
    public function test_second_order_injection_stored_data_used_in_a_later_query_stays_data(string $payload): void
    {
        // Arrange
        $users = $this->connection->table('users');
        $users->insert(['name' => $payload, 'email' => md5($payload) . '@x.dev', 'age' => 2]);
        $value = $users->where('email', md5($payload) . '@x.dev')->value('name');
        $stored = \is_string($value) ? $value : '';

        // Act
        $again = $users->where('name', $stored)->count();
        $viaRaw = $this->connection->select('SELECT COUNT(*) AS c FROM users WHERE name = ?', [$stored]);
        $viaIn = $users->whereIn('name', [$stored, 'nobody'])->count();

        // Assert
        self::assertSame(1, $again);
        self::assertSame(1, $viaRaw[0]['c']);
        self::assertSame(1, $viaIn);
        self::assertSame(4, $users->count());
    }

    public function test_a_stacked_statement_in_a_raw_query_is_not_executed(): void
    {
        // Arrange

        // Act
        try {
            $this->connection->select("SELECT 1; DROP TABLE users");
        } catch (QueryException) {
            // some drivers reject multiple statements outright
        }

        // Assert
        self::assertSame(3, $this->connection->table('users')->count());
    }

    public function test_hostile_identifiers_never_reach_the_database(): void
    {
        // Arrange
        $users = $this->connection->table('users');
        $attempts = [
            static fn() => $users->where('name; DROP TABLE users', 'x'),
            static fn() => $users->orderBy('name) --'),
            static fn() => $users->select('name, password'),
            static fn() => $users->pluck('name" FROM sqlite_master --'),
            static fn() => $users->insert(['name) VALUES (1); DROP TABLE users; --' => 'x']),
            static fn() => $users->where('id', 1)->update(['name = 1; DROP TABLE users; --' => 'x']),
            static fn() => $users->sum('age) FROM users; --'),
        ];
        $rejected = 0;

        // Act
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidQueryException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($attempts), $rejected);
        self::assertSame(3, $users->count());
    }

    public function test_like_input_can_be_made_literal(): void
    {
        // Arrange
        $users = $this->connection->table('users');
        $users->insert(['name' => '100% Real', 'email' => 'p@x.dev', 'age' => 1]);
        $users->insert(['name' => '100 Real', 'email' => 'q@x.dev', 'age' => 1]);

        // Act
        $unescaped = $users->where('name', 'like', '100% Real')->count();
        $escaped = $users->where('name', 'like', Like::escape('100% Real'))->count();
        $wildcard = $users->where('name', 'like', Like::contains('%'))->count();

        // Assert
        self::assertSame(2, $unescaped);
        self::assertSame(1, $escaped);
        self::assertSame(1, $wildcard);
    }

    public function test_query_errors_do_not_leak_the_values_that_caused_them(): void
    {
        // Arrange
        $secret = 'ada@example.com';

        // Act
        try {
            $this->connection->table('users')->insert(['name' => 'X', 'email' => $secret, 'age' => 1]);
            self::fail('Expected a QueryException.');
        } catch (QueryException $e) {
            // Assert
            self::assertStringNotContainsString($secret, $e->getMessage());
            self::assertStringNotContainsString($secret, serialize([$e->sql, $e->sqlState]));
        }
    }

    public function test_the_only_way_to_write_sql_by_hand_is_the_explicit_raw_type(): void
    {
        // Arrange
        $source = (string) file_get_contents(__DIR__ . '/../../packages/database/src/Query/QueryBuilder.php');

        // Act
        $accepting = preg_match_all('/Raw\s+\$/', $source);

        // Assert
        self::assertGreaterThan(0, $accepting);
        self::assertStringNotContainsString('sprintf(', substr($source, (int) strpos($source, 'private function condition'), 900));
    }
}
