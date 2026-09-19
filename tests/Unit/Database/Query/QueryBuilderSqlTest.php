<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Database\Query;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Query\Like;
use Trunk\Database\Query\QueryBuilder;
use Trunk\Database\Query\Raw;
use Trunk\Tests\Support\DatabaseHarness;

/**
 * Golden SQL for every builder feature, on all three dialects. No database is opened.
 */
final class QueryBuilderSqlTest extends TestCase
{
    private const array QUOTES = ['sqlite' => '"', 'pgsql' => '"', 'mysql' => '`'];

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
    public function test_a_typical_query_quotes_identifiers_per_dialect_and_binds_every_value(string $driver): void
    {
        // Arrange
        $query = $this->builder($driver)->where('status', 'active')->where('age', '>', 18)->orderBy('name')->limit(50);

        // Act
        $sql = $query->toSql();

        // Assert
        self::assertSame($this->q($driver, 'SELECT * FROM `users` WHERE `status` = ? AND `age` > ? ORDER BY `name` ASC LIMIT 50'), $sql);
        self::assertSame(['active', 18], $query->getBindings());
    }

    #[DataProvider('drivers')]
    public function test_columns_aliases_distinct_joins_grouping_and_having(string $driver): void
    {
        // Arrange
        $query = $this->builder($driver)
            ->select('users.id', 'users.name as author', 'COUNT_ALIAS')
            ->distinct()
            ->leftJoin('posts', 'posts.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->groupBy('users.id')
            ->having('total', '>', 2);

        // Act
        $sql = $query->toSql();

        // Assert
        self::assertSame($this->q($driver, 'SELECT DISTINCT `users`.`id`, `users`.`name` AS `author`, `COUNT_ALIAS` FROM `users` LEFT JOIN `posts` ON `posts`.`user_id` = `users`.`id` INNER JOIN `roles` ON `roles`.`id` = `users`.`role_id` GROUP BY `users`.`id` HAVING `total` > ?'), $sql);
        self::assertSame([2], $query->getBindings());
    }

    #[DataProvider('drivers')]
    public function test_where_variants_and_their_binding_order(string $driver): void
    {
        // Arrange
        $query = $this->builder($driver)
            ->where('a', 1)
            ->orWhere('b', '<>', 2)
            ->whereIn('c', [3, 4, 5])
            ->whereNotIn('d', ['x'])
            ->whereNull('e')
            ->whereNotNull('f')
            ->whereBetween('g', 6, 7)
            ->where(static fn(QueryBuilder $q): QueryBuilder => $q->where('h', 8)->orWhere('i', 9))
            ->whereColumn('j', '=', 'k')
            ->whereRaw(new Raw('lower(name) = ?', ['ada']));

        // Act
        $sql = $query->toSql();

        // Assert
        self::assertSame($this->q($driver, 'SELECT * FROM `users` WHERE `a` = ? OR `b` <> ? AND `c` IN (?, ?, ?) AND `d` NOT IN (?) AND `e` IS NULL AND `f` IS NOT NULL AND `g` BETWEEN ? AND ? AND (`h` = ? OR `i` = ?) AND `j` = `k` AND (lower(name) = ?)'), $sql);
        self::assertSame([1, 2, 3, 4, 5, 'x', 6, 7, 8, 9, 'ada'], $query->getBindings());
    }

    #[DataProvider('drivers')]
    public function test_null_values_become_is_null_and_empty_in_lists_are_always_false_or_true(string $driver): void
    {
        // Arrange
        $query = $this->builder($driver)->where('a', null)->where('b', '<>', null)->whereIn('c', [])->whereNotIn('d', []);

        // Act
        $sql = $query->toSql();

        // Assert
        self::assertSame($this->q($driver, 'SELECT * FROM `users` WHERE `a` IS NULL AND `b` IS NOT NULL AND 0 = 1 AND 1 = 1'), $sql);
        self::assertSame([], $query->getBindings());
    }

    #[DataProvider('drivers')]
    public function test_like_comparisons_always_carry_the_escape_clause(string $driver): void
    {
        // Arrange
        $query = $this->builder($driver)->where('name', 'like', Like::contains('50%'));

        // Act & Assert
        self::assertSame($this->q($driver, "SELECT * FROM `users` WHERE `name` LIKE ? ESCAPE '!'"), $query->toSql());
        self::assertSame(['%50!%%'], $query->getBindings());
    }

    public function test_limit_and_offset_follow_each_dialect(): void
    {
        // Arrange

        // Act & Assert
        self::assertSame('SELECT * FROM "users" LIMIT 10 OFFSET 20', $this->builder('sqlite')->forPage(3, 10)->toSql());
        self::assertSame('SELECT * FROM `users` LIMIT 10 OFFSET 20', $this->builder('mysql')->forPage(3, 10)->toSql());
        self::assertSame('SELECT * FROM "users" LIMIT 10 OFFSET 20', $this->builder('pgsql')->forPage(3, 10)->toSql());
        self::assertSame('SELECT * FROM "users" LIMIT -1 OFFSET 5', $this->builder('sqlite')->offset(5)->toSql());
        self::assertSame('SELECT * FROM `users` LIMIT 18446744073709551615 OFFSET 5', $this->builder('mysql')->offset(5)->toSql());
        self::assertSame('SELECT * FROM "users" OFFSET 5', $this->builder('pgsql')->offset(5)->toSql());
    }

    public function test_the_builder_is_immutable(): void
    {
        // Arrange
        $base = $this->builder('sqlite')->where('a', 1);

        // Act
        $narrower = $base->where('b', 2)->limit(1);

        // Assert
        self::assertSame('SELECT * FROM "users" WHERE "a" = ?', $base->toSql());
        self::assertSame('SELECT * FROM "users" WHERE "a" = ? AND "b" = ? LIMIT 1', $narrower->toSql());
    }

    /**
     * @return iterable<string, array{callable(QueryBuilder): mixed}>
     */
    public static function unsafeCalls(): iterable
    {
        yield 'table injection' => [static fn(QueryBuilder $b) => new DatabaseHarness()->grammarOnly('sqlite')->table('users; DROP TABLE users')];
        yield 'column injection' => [static fn(QueryBuilder $b) => $b->where('id = 1 OR 1', 1)];
        yield 'select injection' => [static fn(QueryBuilder $b) => $b->select('id, (SELECT password FROM admins)')];
        yield 'order injection' => [static fn(QueryBuilder $b) => $b->orderBy('name; DROP TABLE users')];
        yield 'direction injection' => [static fn(QueryBuilder $b) => $b->orderBy('name', 'asc; DROP TABLE users')];
        yield 'group injection' => [static fn(QueryBuilder $b) => $b->groupBy('a) UNION SELECT')];
        yield 'operator injection' => [static fn(QueryBuilder $b) => $b->where('a', '= 1 OR 1=1 --', 2)];
        yield 'join injection' => [static fn(QueryBuilder $b) => $b->join('posts ON 1=1', 'a', '=', 'b')];
        yield 'join column injection' => [static fn(QueryBuilder $b) => $b->join('posts', 'a', '=', "b' OR '1'='1")];
        yield 'negative limit' => [static fn(QueryBuilder $b) => $b->limit(-1)];
        yield 'negative offset' => [static fn(QueryBuilder $b) => $b->offset(-5)];
        yield 'zero page' => [static fn(QueryBuilder $b) => $b->forPage(0, 10)];
        yield 'array value' => [static fn(QueryBuilder $b) => $b->where('a', [1, 2])];
        yield 'object value' => [static fn(QueryBuilder $b) => $b->where('a', new stdClass())];
        yield 'having injection' => [static fn(QueryBuilder $b) => $b->having('a) OR (1', '>', 1)];
        yield 'between injection' => [static fn(QueryBuilder $b) => $b->whereBetween('a OR 1', 1, 2)];
        yield 'null with greater than' => [static fn(QueryBuilder $b) => $b->where('a', '>', null)];
        yield 'empty group closure' => [static fn(QueryBuilder $b) => $b->where(static fn(QueryBuilder $q) => $q)];
    }

    /**
     * @param callable(QueryBuilder): mixed $attempt
     */
    #[DataProvider('unsafeCalls')]
    public function test_unsafe_or_malformed_input_is_refused_before_any_sql_exists(callable $attempt): void
    {
        // Arrange
        $builder = $this->builder('sqlite');

        // Act & Assert
        $this->expectException(InvalidQueryException::class);
        $attempt($builder);
    }

    public function test_updates_and_deletes_without_a_where_are_refused_unless_explicitly_unrestricted(): void
    {
        // Arrange
        $builder = $this->builder('sqlite');
        $rejected = 0;

        // Act
        foreach ([static fn() => $builder->update(['a' => 1]), static fn() => $builder->delete()] as $attempt) {
            try {
                $attempt();
            } catch (InvalidQueryException $e) {
                ++$rejected;
                self::assertStringContainsString('unrestricted()', $e->getMessage());
            }
        }

        // Assert
        self::assertSame(2, $rejected);
    }

    private function builder(string $driver, string $table = 'users'): QueryBuilder
    {
        return new DatabaseHarness()->grammarOnly($driver)->table($table);
    }

    private function q(string $driver, string $sql): string
    {
        return str_replace('`', self::QUOTES[$driver], $sql);
    }
}
