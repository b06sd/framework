<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Tusk;

use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ViewHarness;
use Trunk\Tusk\Exception\TemplateRuntimeException;
use Trunk\Tusk\Exception\TemplateSyntaxException;
use Trunk\Tusk\Runtime\SafeHtml;

final class RenderingTest extends TestCase
{
    /** @var list<ViewHarness> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function expressions(): iterable
    {
        yield 'precedence' => ['1 + 2 * 3', '7'];
        yield 'parentheses' => ['(1 + 2) * 3', '9'];
        yield 'modulo' => ['10 % 4', '2'];
        yield 'unary minus' => ['-a + 1', '-4'];
        yield 'float' => ['1.5 + 1', '2.5'];
        yield 'and/comparison' => ['a > b and b > 1', '1'];
        yield 'not' => ['not (a > b)', ''];
        yield 'or' => ['a < b or b == 2', '1'];
        yield 'concat' => ["s ~ '!' ~ a", 'Hi!5'];
        yield 'strict equality' => ["a == '5'", ''];
        yield 'not equal' => ['a != 5', ''];
        yield 'in array' => ['2 in list', '1'];
        yield 'in string' => ["'i' in s", '1'];
        yield 'ternary' => ["a > b ? 'big' : 'small'", 'big'];
        yield 'nested ternary' => ["a > 9 ? 'x' : (a > 4 ? 'y' : 'z')", 'y'];
        yield 'property' => ['user.name', 'Ada'];
        yield 'bracket access' => ["user['age']", '36'];
        yield 'list index' => ['list.1', '2'];
        yield 'dynamic key' => ["user[key]", 'Ada'];
        yield 'upper' => ['s|upper', 'HI'];
        yield 'chained filters' => ['s|lower|upper', 'HI'];
        yield 'join' => ["list|join('-')", '1-2-3'];
        yield 'length' => ['list|length', '3'];
        yield 'default on null' => ["n|default('dash')", 'dash'];
        yield 'default on undefined variable' => ["missing|default('m')", 'm'];
        yield 'default on undefined key' => ["user.nope|default('z')", 'z'];
        yield 'default keeps value' => ["s|default('z')", 'Hi'];
        yield 'trim' => ["'  x '|trim", 'x'];
        yield 'json is escaped as text' => ['user|json', '{&quot;name&quot;:&quot;Ada&quot;,&quot;age&quot;:36}'];
        yield 'js is json and not re-escaped' => ['s|js', '"Hi"'];
        yield 'url' => ["'a b/c'|url", 'a%20b%2Fc'];
        yield 'string escapes' => ["'it\\'s'", 'it&apos;s'];
    }

    #[DataProvider('expressions')]
    public function test_expressions_evaluate_as_specified(string $expression, string $expected): void
    {
        // Arrange
        $views = $this->views(['t' => '{{ ' . $expression . ' }}']);
        $data = ['a' => 5, 'b' => 2, 's' => 'Hi', 'n' => null, 'key' => 'name', 'list' => [1, 2, 3], 'user' => ['name' => 'Ada', 'age' => 36]];

        // Act
        $output = $views->render('t', $data);

        // Assert
        self::assertSame($expected, $output);
    }

    public function test_conditionals_choose_the_first_matching_branch(): void
    {
        // Arrange
        $views = $this->views(['t' => '<if test="n == 1">one<elseif test="n == 2">two<else>many</if>']);

        // Act & Assert
        self::assertSame('one', $views->render('t', ['n' => 1]));
        self::assertSame('two', $views->render('t', ['n' => 2]));
        self::assertSame('many', $views->render('t', ['n' => 9]));
    }

    public function test_loops_expose_values_keys_and_an_empty_branch(): void
    {
        // Arrange
        $views = $this->views(['t' => '<for each="items" as="v" key="k">{{ k }}={{ v }};<else>none</for>']);

        // Act & Assert
        self::assertSame('a=1;b=2;', $views->render('t', ['items' => ['a' => 1, 'b' => 2]]));
        self::assertSame('none', $views->render('t', ['items' => []]));
    }

    public function test_set_and_include_scope(): void
    {
        // Arrange
        $views = $this->views([
            'row' => '[{{ label }}:{{ value|default("-") }}:{{ outer }}]',
            't' => '<set name="outer" value="\'O\'" /><include name="row" label="\'A\'" value="1 + 1" /><include name="row" label="\'B\'" />',
        ]);

        // Act
        $output = $views->render('t');

        // Assert
        self::assertSame('[A:2:O][B:-:O]', $output);
    }

    public function test_layouts_fill_slots_and_use_fallbacks(): void
    {
        // Arrange
        $views = $this->views([
            'layouts/app' => '<title><slot name="title">Default</slot></title><main><slot /></main><slot name="footer">F</slot>',
            'page' => '<layout name="app"><fill slot="title">{{ t }}</fill>Body {{ t }}</layout>',
        ]);

        // Act
        $output = $views->render('page', ['t' => 'Hello']);

        // Assert
        self::assertSame('<title>Hello</title><main>Body Hello</main>F', $output);
    }

    public function test_layouts_can_be_nested_and_each_level_only_sees_its_own_children(): void
    {
        // Arrange
        $views = $this->views([
            'layouts/base' => '<html><slot name="head">no-head</slot>|<slot /></html>',
            'layouts/wrap' => '<layout name="base"><fill slot="head">wrap-head</fill><div><slot /></div></layout>',
            'page' => '<layout name="wrap">page body</layout>',
        ]);

        // Act
        $output = $views->render('page');

        // Assert
        self::assertSame('<html>wrap-head|<div>page body</div></html>', $output);
    }

    public function test_the_shipped_fixture_views_render_together(): void
    {
        // Arrange
        $renderer = new \Trunk\Tusk\Renderer(new \Trunk\Tusk\Loader\SourceTemplateLoader([__DIR__ . '/../../Fixtures/views'], sys_get_temp_dir() . '/trunk-fixture-cache-' . bin2hex(random_bytes(3))));

        // Act
        $html = $renderer->render('users/index', ['title' => 'Team', 'users' => [['name' => 'Ada', 'email' => 'ada@x.dev'], ['name' => 'Bo', 'email' => 'bo@x.dev']]]);
        $empty = $renderer->render('users/index', ['title' => 'Team', 'users' => []]);

        // Assert
        self::assertStringContainsString('<title>Team</title>', $html);
        self::assertStringContainsString('<article>Ada (ada@x.dev)</article>', $html);
        self::assertStringContainsString('2 users', $html);
        self::assertStringContainsString('default footer', $html);
        self::assertStringContainsString('No users', $empty);
        self::assertStringContainsString('nobody', $empty);
    }

    public function test_undefined_variables_fail_fast_but_default_tolerates_them(): void
    {
        // Arrange
        $views = $this->views(['t' => '{{ nope }}']);

        // Act & Assert
        $this->expectException(TemplateRuntimeException::class);
        $this->expectExceptionMessage('Undefined variable "nope"');
        $views->render('t');
    }

    public function test_output_buffers_are_unwound_when_rendering_fails(): void
    {
        // Arrange
        $views = $this->views(['t' => 'partial output {{ nope }}']);
        $before = ob_get_level();

        // Act
        try {
            $views->render('t');
            self::fail('Expected an exception.');
        } catch (TemplateRuntimeException) {
            // Assert
            self::assertSame($before, ob_get_level());
        }
    }

    public function test_only_public_properties_and_array_access_are_readable(): void
    {
        // Arrange
        $object = new class {
            public string $open = 'yes';

            public function __construct(private readonly string $secret = 's3cret') {}

            public function secretLength(): int
            {
                return \strlen($this->secret);
            }
        };
        $views = $this->views(['ok' => '{{ o.open }}{{ a.k }}', 'secret' => '{{ o.secret }}', 'method' => '{{ o.secretLength }}']);

        // Act & Assert
        self::assertSame('yesv', $views->render('ok', ['o' => $object, 'a' => new ArrayObject(['k' => 'v'])]));

        foreach (['secret', 'method'] as $template) {
            try {
                $views->render($template, ['o' => $object]);
                self::fail('Expected an exception for ' . $template);
            } catch (TemplateRuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_safe_html_values_are_not_escaped_and_raw_is_the_only_raw_form(): void
    {
        // Arrange
        $views = $this->views(['t' => '{{ html }}|<raw value="html" />|{{ safe }}']);

        // Act
        $output = $views->render('t', ['html' => '<b>x</b>', 'safe' => new SafeHtml('<i>y</i>')]);

        // Assert
        self::assertSame('&lt;b&gt;x&lt;/b&gt;|<b>x</b>|<i>y</i>', $output);
    }

    public function test_values_that_cannot_be_printed_are_errors(): void
    {
        // Arrange
        $views = $this->views(['t' => '{{ v }}']);

        // Act & Assert
        $this->expectException(TemplateRuntimeException::class);
        $views->render('t', ['v' => [1, 2]]);
    }

    public function test_invalid_syntax_surfaces_with_template_and_line(): void
    {
        // Arrange
        $views = $this->views(['broken' => "ok\n{{ 1 + }}"]);

        // Act & Assert
        $this->expectException(TemplateSyntaxException::class);
        $this->expectExceptionMessageMatches('/^broken:2:/');
        $views->render('broken');
    }

    public function test_circular_includes_are_stopped(): void
    {
        // Arrange
        $views = $this->views(['a' => '<include name="b" />', 'b' => '<include name="a" />']);

        // Act & Assert
        $this->expectException(TemplateRuntimeException::class);
        $views->render('a');
    }

    /**
     * @param array<string, string> $templates
     */
    private function views(array $templates): ViewHarness
    {
        return $this->harnesses[] = new ViewHarness($templates);
    }
}
