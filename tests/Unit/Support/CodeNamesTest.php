<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Support;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Support\CodeNames;

final class CodeNamesTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileClasses(): iterable
    {
        yield 'statement' => ["App\\Foo'; system('id'); //"];
        yield 'space' => ['App\\Foo Bar'];
        yield 'newline' => ["App\\Foo\nBar"];
        yield 'null byte' => ["App\\Foo\0"];
        yield 'path' => ['../../etc/passwd'];
        yield 'empty' => [''];
        yield 'brace' => ['App\\Foo{}'];
        yield 'double backslash' => ['App\\\\Foo'];
    }

    #[DataProvider('hostileClasses')]
    public function test_hostile_class_names_are_refused_and_not_echoed_raw(string $class): void
    {
        // Arrange & Act
        try {
            CodeNames::className($class);
            self::fail('Expected a InvalidArgumentException.');
        } catch (InvalidArgumentException $e) {
            // Assert
            self::assertStringNotContainsString("'", $e->getMessage());
            self::assertStringNotContainsString("\n" . 'Bar', $e->getMessage());
            self::assertStringNotContainsString("\0", $e->getMessage());
        }
    }

    public function test_valid_names_are_returned_fully_qualified_and_properties_unchanged(): void
    {
        // Arrange & Act & Assert
        self::assertSame('\\App\\Orm\\Customer', CodeNames::className('App\\Orm\\Customer'));
        self::assertSame('\\App\\Orm\\Customer', CodeNames::className('\\App\\Orm\\Customer'));
        self::assertSame('createdAt', CodeNames::property('createdAt'));
        $this->expectException(InvalidArgumentException::class);
        CodeNames::property('a b');
    }
}
