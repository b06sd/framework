<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Database\Query;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Query\Identifier;
use Trunk\Database\Query\Like;
use Trunk\Database\Query\Operator;
use Trunk\Database\Query\Value;

final class IdentifierAndOperatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validIdentifiers(): iterable
    {
        yield 'simple' => ['users'];
        yield 'underscore' => ['user_id'];
        yield 'qualified' => ['users.name'];
        yield 'wildcard' => ['*'];
        yield 'qualified wildcard' => ['users.*'];
        yield 'alias' => ['name as n'];
        yield 'qualified alias' => ['users.name AS n'];
    }

    #[DataProvider('validIdentifiers')]
    public function test_plain_identifiers_are_accepted(string $name): void
    {
        // Arrange

        // Act & Assert
        self::assertTrue(Identifier::isValid($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => ['user name'];
        yield 'quote' => ['users"'];
        yield 'backtick' => ['users`'];
        yield 'semicolon' => ['users; DROP TABLE users'];
        yield 'comment' => ['users--'];
        yield 'block comment' => ['users/*x*/'];
        yield 'parentheses' => ['count(*)'];
        yield 'leading digit' => ['1users'];
        yield 'dash' => ['user-name'];
        yield 'dollar' => ['$name'];
        yield 'newline' => ["users\nid"];
        yield 'nul' => ["users\0"];
        yield 'unicode' => ["us\u{e9}rs"];
        yield 'too long' => [str_repeat('a', 65)];
        yield 'double dot' => ['a.b.c.'];
        yield 'alias injection' => ['name as n; DROP TABLE x'];
        yield 'or true' => ["id' OR '1'='1"];
    }

    #[DataProvider('hostileIdentifiers')]
    public function test_anything_that_is_not_a_plain_identifier_is_refused(string $name): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(InvalidQueryException::class);
        Identifier::assert($name);
    }

    public function test_column_references_allow_qualification_but_not_wildcards_or_aliases(): void
    {
        // Arrange
        $rejected = 0;

        // Act
        Identifier::assertColumn('users.name');

        foreach (['*', 'users.*', 'name as n', 'a.b.c'] as $bad) {
            try {
                Identifier::assertColumn($bad);
            } catch (InvalidQueryException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function operators(): iterable
    {
        yield 'equals' => ['=', '='];
        yield 'not equals' => ['!=', '!='];
        yield 'like lower' => ['like', 'LIKE'];
        yield 'not like padded' => ['  NOT LIKE ', 'NOT LIKE'];
        yield 'less or equal' => ['<=', '<='];
    }

    #[DataProvider('operators')]
    public function test_whitelisted_operators_are_normalised(string $input, string $expected): void
    {
        // Arrange

        // Act & Assert
        self::assertSame($expected, Operator::normalize($input));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badOperators(): iterable
    {
        yield 'injection' => ['= 1 OR 1=1 --'];
        yield 'or' => ['OR'];
        yield 'semicolon' => [';'];
        yield 'union' => ['UNION SELECT'];
        yield 'empty' => [''];
        yield 'is' => ['IS'];
        yield 'in' => ['IN'];
        yield 'newline' => ["=\n1"];
    }

    #[DataProvider('badOperators')]
    public function test_anything_outside_the_operator_whitelist_is_refused(string $operator): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(InvalidQueryException::class);
        Operator::normalize($operator);
    }

    public function test_only_scalars_null_and_dates_can_be_query_values(): void
    {
        // Arrange
        $rejected = 0;

        // Act
        foreach ([[1, 2], new stdClass(), static fn(): int => 1, fopen('php://memory', 'r')] as $bad) {
            try {
                Value::normalize($bad);
            } catch (InvalidQueryException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
        self::assertSame('2026-01-02 03:04:05', Value::normalize(new DateTimeImmutable('2026-01-02 03:04:05')));
        self::assertNull(Value::normalize(null));
        self::assertSame(1.5, Value::normalize(1.5));
    }

    public function test_like_text_is_escaped_so_wildcards_lose_their_meaning(): void
    {
        // Arrange

        // Act & Assert
        self::assertSame('100!% sure!!', Like::escape('100% sure!'));
        self::assertSame('a!_b', Like::escape('a_b'));
        self::assertSame('%x!%%', Like::contains('x%'));
        self::assertSame('x!_%', Like::startsWith('x_'));
        self::assertSame('%!%y', Like::endsWith('%y'));
    }
}
