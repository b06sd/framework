<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Validation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Rules\Date;
use Trunk\Validation\Rules\Each;
use Trunk\Validation\Rules\Email;
use Trunk\Validation\Rules\Ip;
use Trunk\Validation\Rules\Items;
use Trunk\Validation\Rules\Length;
use Trunk\Validation\Rules\OneOf;
use Trunk\Validation\Rules\Pattern;
use Trunk\Validation\Rules\Range;
use Trunk\Validation\Rules\Required;
use Trunk\Validation\Rules\SameAs;
use Trunk\Validation\Rules\Url;
use Trunk\Validation\Rules\Uuid;
use Trunk\Validation\Violation;

final class RulesTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_a_rule_accepts_or_rejects_a_value(Rule $rule, mixed $value, bool $valid): void
    {
        // Arrange
        $context = new Context('field', ['other' => 'same']);

        // Act
        $violation = $rule->check($value, $context);

        // Assert
        self::assertSame($valid, $violation === null, $violation instanceof Violation ? $violation->message : 'expected a violation');
    }

    /**
     * @return iterable<string, array{Rule, mixed, bool}>
     */
    public static function cases(): iterable
    {
        yield 'email ok' => [new Email(), 'ada@example.com', true];
        yield 'email plus tag' => [new Email(), 'ada+tag@example.co.uk', true];
        yield 'email no domain dot' => [new Email(), 'ada@localhost', false];
        yield 'email space' => [new Email(), 'ada @example.com', false];
        yield 'email newline' => [new Email(), "ada@example.com\n", false];
        yield 'email unicode' => [new Email(), 'adá@example.com', false];
        yield 'email too long' => [new Email(), str_repeat('a', 250) . '@b.co', false];
        yield 'email local too long' => [new Email(), str_repeat('a', 65) . '@example.com', false];
        yield 'email not text' => [new Email(), 12, false];

        yield 'url https' => [new Url(), 'https://example.com/a?b=c', true];
        yield 'url javascript' => [new Url(), 'javascript:alert(1)', false];
        yield 'url data' => [new Url(), 'data:text/html,x', false];
        yield 'url no host' => [new Url(), 'https://', false];
        yield 'url newline' => [new Url(), "https://example.com/\nX: y", false];
        yield 'url relative' => [new Url(), '/path', false];
        yield 'url custom scheme' => [new Url(['ftp']), 'ftp://example.com', true];
        yield 'url scheme not listed' => [new Url(['ftp']), 'https://example.com', false];

        yield 'uuid v4' => [new Uuid(), '123e4567-e89b-42d3-a456-426614174000', true];
        yield 'uuid bad' => [new Uuid(), '123e4567e89b42d3a456426614174000', false];
        yield 'ip v4' => [new Ip(4), '10.0.0.1', true];
        yield 'ip v6 on v4' => [new Ip(4), '::1', false];
        yield 'ip any v6' => [new Ip(), '::1', true];
        yield 'ip octal' => [new Ip(), '010.0.0.1', false];

        yield 'length ok' => [new Length(2, 4), 'abc', true];
        yield 'length counts characters' => [new Length(max: 3), 'ééé', true];
        yield 'length too short' => [new Length(min: 3), 'ab', false];
        yield 'length too long' => [new Length(max: 3), 'abcd', false];

        yield 'range ok' => [new Range(1, 5), 5, true];
        yield 'range float' => [new Range(0.5, 1.5), 1.5, true];
        yield 'range under' => [new Range(min: 1), 0, false];
        yield 'range over' => [new Range(max: 5), 6, false];

        yield 'items ok' => [new Items(max: 2), ['a', 'b'], true];
        yield 'items over' => [new Items(max: 1), ['a', 'b'], false];
        yield 'items under' => [new Items(min: 1), [], false];

        yield 'oneof ok' => [new OneOf(['a', 1]), 1, true];
        yield 'oneof strict' => [new OneOf(['1']), 1, false];
        yield 'pattern ok' => [new Pattern('/^[a-z]+$/D'), 'abc', true];
        yield 'pattern trailing newline (D flag)' => [new Pattern('/^[a-z]+$/D'), "abc\n", false];
        yield 'date ok' => [new Date(), '2026-02-28', true];
        yield 'date impossible' => [new Date(), '2026-02-30', false];
        yield 'date format' => [new Date('d/m/Y'), '31/12/2026', true];
        yield 'date not padded' => [new Date(), '2026-2-3', false];

        yield 'same ok' => [new SameAs('other'), 'same', true];
        yield 'same differs' => [new SameAs('other'), 'nope', false];
        yield 'required blank' => [new Required(), "  \t", false];
        yield 'required empty list' => [new Required(), [], false];
        yield 'required zero' => [new Required(), 0, true];
        yield 'required false' => [new Required(), false, true];
        yield 'each ok' => [new Each(new Length(max: 2)), ['a', 'bc'], true];
        yield 'each fails on item' => [new Each(new Length(max: 2)), ['a', 'bcd'], false];
    }

    public function test_each_names_the_failing_item_by_position(): void
    {
        // Arrange
        $rule = new Each(new Length(max: 2));

        // Act
        $violation = $rule->check(['a', 'bcd'], new Context('tags'));

        // Assert
        self::assertSame('Item 2: Must be at most 2 characters.', $violation?->message);
    }

    #[DataProvider('invalidConfigurations')]
    public function test_a_rule_configured_wrongly_fails_when_the_class_is_loaded(callable $make): void
    {
        // Arrange
        $this->expectException(InvalidArgumentException::class);

        // Act
        $make();
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'length with nothing' => [static fn() => new Length()];
        yield 'length min above max' => [static fn() => new Length(5, 2)];
        yield 'range with nothing' => [static fn() => new Range()];
        yield 'items negative' => [static fn() => new Items(min: -1)];
        yield 'ip version 5' => [static fn() => new Ip(5)];
        yield 'broken pattern' => [static fn() => new Pattern('/(/')];
    }
}
