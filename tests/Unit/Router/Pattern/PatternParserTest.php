<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Pattern;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Pattern\PatternParser;

final class PatternParserTest extends TestCase
{
    public function test_literals_params_constraints_and_optional_tails_are_parsed(): void
    {
        // Arrange
        $parser = new PatternParser();

        // Act
        $parsed = $parser->parse('/users/{id:int}/posts/{slug?}');

        // Assert
        self::assertSame(['id', 'slug'], $parsed->paramNames());
        self::assertFalse($parsed->isStatic());
        self::assertSame('[0-9]+', $parsed->segments[1]->regex);
        self::assertSame('[^/]+', $parsed->segments[3]->regex);
        self::assertTrue($parsed->segments[3]->optional);
        self::assertCount(2, $parsed->variants());
        self::assertCount(3, $parsed->variants()[1]);
    }

    public function test_the_root_and_plain_paths_are_static(): void
    {
        // Arrange
        $parser = new PatternParser();

        // Act & Assert
        self::assertTrue($parser->parse('/')->isStatic());
        self::assertTrue($parser->parse('/about/us')->isStatic());
        self::assertCount(1, $parser->parse('/about')->variants());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedConstraints(): iterable
    {
        yield 'lowercase class' => ['/x/{a:[a-z]+}'];
        yield 'bounded digits' => ['/x/{a:\d{2,4}}'];
        yield 'single char' => ['/x/{a:[abc]}'];
        yield 'alias slug' => ['/x/{a:slug}'];
        yield 'alias uuid' => ['/x/{a:uuid}'];
    }

    #[DataProvider('acceptedConstraints')]
    public function test_safe_constraints_are_accepted(string $pattern): void
    {
        // Arrange
        $parser = new PatternParser();

        // Act
        $parsed = $parser->parse($pattern);

        // Assert
        self::assertSame(['a'], $parsed->paramNames());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'no leading slash' => ['users'];
        yield 'empty' => [''];
        yield 'empty segment' => ['/a//b'];
        yield 'trailing slash' => ['/a/'];
        yield 'unclosed brace' => ['/a/{id'];
        yield 'bad param name' => ['/a/{1x}'];
        yield 'mixed segment' => ['/a/file.{ext}'];
        yield 'duplicate param' => ['/{a}/{a}'];
        yield 'optional in the middle' => ['/{a?}/b'];
        yield 'catch-all in the middle' => ['/{a:any}/b'];
        yield 'group constraint' => ['/x/{a:(a|b)+}'];
        yield 'nested quantifier' => ['/x/{a:([a-z]+)+}'];
        yield 'lookahead' => ['/x/{a:(?=a)a}'];
        yield 'star quantifier' => ['/x/{a:[a-z]*}'];
        yield 'zero lower bound' => ['/x/{a:[a-z]{0,3}}'];
        yield 'tilde delimiter' => ['/x/{a:[~]+}'];
        yield 'class matching slash' => ['/x/{a:[!-~]+}'];
        yield 'negated class' => ['/x/{a:[^.]+}'];
        yield 'non-word escape' => ['/x/{a:\W+}'];
        yield 'unknown alias' => ['/x/{a:nope}'];
        yield 'space in literal' => ['/a b'];
    }

    #[DataProvider('invalidPatterns')]
    public function test_invalid_patterns_are_rejected(string $pattern): void
    {
        // Arrange
        $parser = new PatternParser();

        // Act & Assert
        $this->expectException(InvalidRouteException::class);
        $parser->parse($pattern);
    }
}
