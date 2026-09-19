<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Message;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Message\Headers;

final class HeadersTest extends TestCase
{
    public function test_lookup_is_case_insensitive_and_preserves_the_original_name(): void
    {
        // Arrange
        $headers = Headers::empty()->with('Content-Type', 'text/html');

        // Act
        $values = $headers->get('content-TYPE');

        // Assert
        self::assertSame(['text/html'], $values);
        self::assertSame(['Content-Type' => ['text/html']], $headers->all());
    }

    public function test_with_replaces_and_with_added_appends(): void
    {
        // Arrange
        $headers = Headers::empty()->with('Accept', 'a');

        // Act
        $added = $headers->withAdded('accept', ['b', 'c']);
        $replaced = $added->with('ACCEPT', 'z');

        // Assert
        self::assertSame(['a', 'b', 'c'], $added->get('Accept'));
        self::assertSame(['z'], $replaced->get('Accept'));
        self::assertSame(['a'], $headers->get('Accept'));
    }

    public function test_without_removes_a_header(): void
    {
        // Arrange
        $headers = Headers::empty()->with('X-A', '1');

        // Act
        $result = $headers->without('x-a');

        // Assert
        self::assertFalse($result->has('X-A'));
    }

    public function test_values_are_trimmed_of_surrounding_whitespace(): void
    {
        // Arrange

        // Act
        $headers = Headers::empty()->with('X-A', " \t value \t ");

        // Assert
        self::assertSame(['value'], $headers->get('X-A'));
    }

    public function test_with_first_moves_the_header_to_the_front(): void
    {
        // Arrange
        $headers = Headers::empty()->with('Accept', 'a');

        // Act
        $result = $headers->withFirst('Host', 'example.com');

        // Assert
        self::assertSame(['Host', 'Accept'], array_keys($result->all()));
    }

    public function test_invalid_names_and_values_are_rejected(): void
    {
        // Arrange
        $headers = Headers::empty();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $headers->with('Bad Name', 'x');
    }

    public function test_non_string_values_and_empty_lists_are_rejected(): void
    {
        // Arrange
        $headers = Headers::empty();

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $headers->with('X-A', []);
    }
}
