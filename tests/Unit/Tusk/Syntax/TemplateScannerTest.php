<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Tusk\Syntax;

use PHPUnit\Framework\TestCase;
use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\Syntax\TemplateScanner;
use Trunk\Tusk\Syntax\TokenKind;

final class TemplateScannerTest extends TestCase
{
    public function test_text_outputs_and_tusk_tags_are_separated(): void
    {
        // Arrange
        $source = '<h1>{{ title }}</h1><for each="users" as="u">x</for>';

        // Act
        $tokens = new TemplateScanner()->scan($source, 't');

        // Assert
        self::assertSame([TokenKind::Text, TokenKind::Output, TokenKind::Text, TokenKind::Open, TokenKind::Text, TokenKind::Close], array_map(static fn($t): TokenKind => $t->kind, $tokens));
        self::assertSame('title', $tokens[1]->text);
        self::assertSame(['each' => 'users', 'as' => 'u'], $tokens[3]->attributes);
    }

    public function test_ordinary_html_is_left_alone_even_when_it_resembles_a_tusk_tag(): void
    {
        // Arrange
        $source = '<form action="/x"><iframe></iframe><format>';

        // Act
        $tokens = new TemplateScanner()->scan($source, 't');

        // Assert
        self::assertCount(1, $tokens);
        self::assertSame($source, $tokens[0]->text);
    }

    public function test_quotes_inside_outputs_and_attributes_may_contain_delimiters(): void
    {
        // Arrange
        $source = '{{ \'}}\' ~ "}}" }}<if test="a > 1 and b == \'>\'">x</if>';

        // Act
        $tokens = new TemplateScanner()->scan($source, 't');

        // Assert
        self::assertSame('\'}}\' ~ "}}"', $tokens[0]->text);
        self::assertSame('a > 1 and b == \'>\'', $tokens[1]->attributes['test']);
    }

    public function test_comments_are_dropped_and_lines_are_tracked(): void
    {
        // Arrange
        $source = "line1\n<comment>\nhidden {{ x }}\n</comment>\n{{ y }}";

        // Act
        $tokens = new TemplateScanner()->scan($source, 't');

        // Assert
        $output = array_values(array_filter($tokens, static fn($t): bool => $t->kind === TokenKind::Output));
        self::assertCount(1, $output);
        self::assertSame('y', $output[0]->text);
        self::assertSame(5, $output[0]->line);
        self::assertStringNotContainsString('hidden', implode('', array_map(static fn($t): string => $t->text, $tokens)));
    }

    public function test_self_closing_tags_are_recognised(): void
    {
        // Arrange
        $source = '<include name="a/b" />';

        // Act
        $tokens = new TemplateScanner()->scan($source, 't');

        // Assert
        self::assertTrue($tokens[0]->selfClosing);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'php open tag' => ['<?php echo 1; ?>'];
        yield 'short echo' => ['<?= 1 ?>'];
        yield 'unterminated output' => ['{{ a'];
        yield 'empty output' => ['{{ }}'];
        yield 'unterminated tag' => ['<if test="a"'];
        yield 'unquoted attribute' => ['<if test=a>'];
        yield 'valueless attribute' => ['<if test>'];
        yield 'duplicate attribute' => ['<if test="a" test="b">'];
        yield 'unterminated comment' => ['<comment>never closed'];
        yield 'unterminated quote' => ['<if test="a>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_malformed_templates_are_rejected_with_a_position(string $source): void
    {
        // Arrange
        $scanner = new TemplateScanner();

        // Act & Assert
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessageMatches('/^t:\d+:/');
        $scanner->scan($source, 't');
    }
}
