<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Query\QueryBuilder;
use Trunk\Tests\Support\DatabaseHarness;

/**
 * A connection reuses one grammar for every query (compiling a query is measurably cheaper when the
 * grammar's memo of wrapped identifiers survives between queries). That must change nothing about the
 * SQL produced and must never let a bad identifier through or grow without bound.
 */
final class GrammarSharingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function drivers(): iterable
    {
        yield 'sqlite' => ['sqlite'];
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('drivers')]
    public function test_the_connection_reuses_one_grammar_and_it_produces_the_same_sql_as_a_fresh_one(string $driver): void
    {
        // Arrange
        $connection = new DatabaseHarness()->grammarOnly($driver);
        $shared = $connection->grammar();
        $queries = [
            static fn(Connection $c): QueryBuilder => $c->table('users')->select('id', 'users.name as n', 'email')->where('age', '>', 18)->orWhere('name', 'like', 'a%')->orderBy('name', 'desc')->limit(5)->offset(10),
            static fn(Connection $c): QueryBuilder => $c->table('orders as o')->join('users', 'users.id', '=', 'o.user_id')->whereIn('o.status', ['a', 'b'])->groupBy('o.status'),
            static fn(Connection $c): QueryBuilder => $c->table('t')->whereNull('deleted_at')->whereBetween('n', 1, 9),
        ];

        // Act & Assert
        self::assertSame($shared, $connection->grammar());

        foreach ($queries as $make) {
            $sql = $make($connection)->toSql();

            for ($i = 0; $i < 3; ++$i) {
                self::assertSame($sql, $make($connection)->toSql(), 'a repeated query compiles to identical SQL');
            }
        }
    }

    #[DataProvider('drivers')]
    public function test_remembering_wrapped_identifiers_never_lets_an_invalid_one_through_and_stays_bounded(string $driver): void
    {
        // Arrange
        $grammar = new DatabaseHarness()->grammarOnly($driver)->grammar();
        $rejected = 0;

        // Act
        foreach (range(1, 6000) as $i) {
            $grammar->wrap('col_' . $i);
        }

        foreach (['a b', 'a;b', 'a"b', "a\0", 'a.b.c.', '', 'a--', "col_1'"] as $bad) {
            try {
                $grammar->wrap($bad);
            } catch (InvalidQueryException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(8, $rejected);
        self::assertNotSame('', $grammar->wrap('col_1'));
        self::assertNotSame('', $grammar->wrap('col_5999'), 'past the memo bound a name is still wrapped correctly, just not remembered');
        self::assertSame($grammar->wrap('users.name as n'), $grammar->wrap('users.name as n'));
    }
}
