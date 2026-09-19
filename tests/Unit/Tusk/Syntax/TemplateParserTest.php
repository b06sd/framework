<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Tusk\Syntax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\Syntax\Node\ForNode;
use Trunk\Tusk\Syntax\Node\IfNode;
use Trunk\Tusk\Syntax\TemplateParser;

final class TemplateParserTest extends TestCase
{
    public function test_nested_structures_are_parsed(): void
    {
        // Arrange
        $source = '<for each="rows" as="row" key="i"><if test="row"><if test="i">a<else>b</if></if></for>';

        // Act
        $template = new TemplateParser()->parse($source, 't');

        // Assert
        self::assertNull($template->layout);
        self::assertInstanceOf(ForNode::class, $template->body[0]);
        self::assertSame('i', $template->body[0]->key);
        self::assertInstanceOf(IfNode::class, $template->body[0]->body[0]);
    }

    public function test_a_layout_collects_default_content_and_named_fills(): void
    {
        // Arrange
        $source = '<layout name="app"><fill slot="title">T</fill>Body</layout>';

        // Act
        $template = new TemplateParser()->parse($source, 't');

        // Assert
        self::assertNotNull($template->layout);
        self::assertSame('layouts/app', $template->layout->template);
        self::assertSame(['title'], array_keys($template->layout->fills));
        self::assertCount(1, $template->layout->default);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'unclosed if' => ['<if test="a">x'];
        yield 'stray closing tag' => ['x</for>'];
        yield 'mismatched closing tag' => ['<if test="a">x</for>'];
        yield 'else outside if' => ['<else>'];
        yield 'elseif outside if' => ['<elseif test="a">'];
        yield 'fill outside layout' => ['<fill slot="x">y</fill>'];
        yield 'text outside layout' => ['oops<layout name="app">x</layout>'];
        yield 'two layouts' => ['<layout name="a">x</layout><layout name="b">y</layout>'];
        yield 'layout traversal' => ['<layout name="../secret">x</layout>'];
        yield 'duplicate fill' => ['<layout name="a"><fill slot="x">1</fill><fill slot="x">2</fill></layout>'];
        yield 'include not self closing' => ['<include name="a/b">'];
        yield 'include bad name' => ['<include name="../etc/passwd" />'];
        yield 'dynamic include name' => ['<include name="{{ x }}" />'];
        yield 'include bad variable' => ['<include name="a" 9x="1" />'];
        yield 'set bad name' => ['<set name="a b" value="1" />'];
        yield 'for missing each' => ['<for as="x">y</for>'];
        yield 'for bad variable' => ['<for each="a" as="a-b">y</for>'];
        yield 'if missing test' => ['<if>x</if>'];
        yield 'raw missing value' => ['<raw />'];
        yield 'self closing if' => ['<if test="a" />'];
    }

    #[DataProvider('invalid')]
    public function test_structural_mistakes_are_rejected(string $source): void
    {
        // Arrange
        $parser = new TemplateParser();

        // Act & Assert
        $this->expectException(TemplateSyntaxException::class);
        $parser->parse($source, 't');
    }
}
