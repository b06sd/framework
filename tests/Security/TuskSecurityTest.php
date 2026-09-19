<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;
use Trunk\Tests\Support\ForbiddenConstructScanner;
use Trunk\Tests\Support\ViewHarness;
use Trunk\Tusk\Compiler\TemplateCompiler;
use Trunk\Tusk\Exception\TemplateSyntaxException;

final class TuskSecurityTest extends TestCase
{
    private ViewHarness $views;

    protected function setUp(): void
    {
        $this->views = new ViewHarness();
    }

    protected function tearDown(): void
    {
        $this->views->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xssPayloads(): iterable
    {
        yield 'script tag' => ['<script>alert(1)</script>'];
        yield 'attribute breakout double' => ['"><img src=x onerror=alert(1)>'];
        yield 'attribute breakout single' => ["' onmouseover='alert(1)"];
        yield 'svg' => ['<svg/onload=alert(1)>'];
        yield 'entity smuggling' => ['&lt;script&gt;'];
        yield 'style' => ['</style><script>x</script>'];
    }

    #[DataProvider('xssPayloads')]
    public function test_output_is_escaped_in_text_and_quoted_attributes(string $payload): void
    {
        // Arrange
        $this->views->write('t', '<p>{{ x }}</p><a title="{{ x }}" data-x=\'{{ x }}\'>l</a>');

        // Act
        $html = $this->views->render('t', ['x' => $payload]);

        // Assert
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<svg', $html);
        self::assertStringNotContainsString('</style>', $html);
        self::assertSame(htmlspecialchars($payload, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5), substr($html, 3, \strlen(htmlspecialchars($payload, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5))));
    }

    public function test_invalid_utf8_is_substituted_not_passed_through(): void
    {
        // Arrange
        $this->views->write('t', '{{ x }}');

        // Act
        $html = $this->views->render('t', ['x' => "ok\xC3\x28bad"]);

        // Assert
        self::assertStringContainsString("\u{FFFD}", $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function codeInjection(): iterable
    {
        yield 'function call' => ["{{ system('id') }}"];
        yield 'method call' => ['{{ user.delete() }}'];
        yield 'call in filter argument' => ["{{ x|default(system('id')) }}"];
        yield 'call in tag attribute' => ['<if test="phpinfo()">x</if>'];
        yield 'statement separator' => ['{{ a; b }}'];
        yield 'php variable' => ['{{ $x }}'];
        yield 'backtick' => ['{{ `id` }}'];
        yield 'assignment' => ['{{ a = 1 }}'];
        yield 'unknown filter' => ['{{ x|nonexistent }}'];
        yield 'php tag' => ['<?php system("id"); ?>'];
        yield 'php short echo' => ['<?= `id` ?>'];
        yield 'static access' => ['{{ Foo::bar }}'];
        yield 'closure' => ['{{ fn() }}'];
        yield 'namespace' => ['{{ \\Foo\\bar }}'];
        yield 'dynamic include' => ['<include name="{{ x }}" />'];
        yield 'include traversal' => ['<include name="../../etc/passwd" />'];
        yield 'layout traversal' => ['<layout name="../../etc/passwd">x</layout>'];
    }

    #[DataProvider('codeInjection')]
    public function test_template_content_cannot_reach_php(string $template): void
    {
        // Arrange
        $compiler = new TemplateCompiler();

        // Act & Assert
        $this->expectException(TemplateSyntaxException::class);
        $compiler->compile($template, 'evil');
    }

    public function test_hostile_literal_text_stays_data_and_compiled_output_only_calls_context_helpers(): void
    {
        // Arrange
        $hostile = "'); system('id'); //  ?>  \\  \${x}  {\$y}  \\' \" <?  end";
        $source = str_replace('<?', '&lt;?', $hostile) . "\n" . '{{ "\'); system(\'id\'); // ?> \\\\ ${x}" }}' . "\n" . '<if test="true">' . str_replace('<?', '&lt;?', $hostile) . '</if>';
        $compiled = new TemplateCompiler()->compile($source, 'hostile');
        $this->views->write('hostile', $source);

        // Act
        $output = $this->views->render('hostile');
        $violations = new ForbiddenConstructScanner()->scan($compiled->php, 'hostile');
        $tokens = PhpToken::tokenize($compiled->php);
        $badCalls = [];
        $badTokens = [];

        foreach ($tokens as $i => $token) {
            if ($token->is([\T_INCLUDE, \T_INCLUDE_ONCE, \T_REQUIRE, \T_REQUIRE_ONCE, \T_EVAL, \T_EXIT, \T_GLOBAL])) {
                $badTokens[] = $token->text;
            }

            $next = $tokens[$i + 1] ?? null;
            $previous = $tokens[$i - 1] ?? null;

            if ($token->is(\T_STRING) && $next?->text === '(' && $previous?->text !== '->') {
                $badCalls[] = $token->text;
            }
        }

        // Assert
        self::assertSame([], $violations);
        self::assertSame([], $badTokens);
        self::assertSame([], $badCalls);
        self::assertStringContainsString("'); system('id'); //", $output);
        self::assertStringContainsString('${x}', $output);
    }

    public function test_a_view_root_cannot_be_escaped_through_template_names(): void
    {
        // Arrange
        file_put_contents($this->views->base . '/secret.tusk.php', 'SECRET');
        $names = ['../secret', '..%2Fsecret', '....//secret', 'a/../../secret', "secret\0"];
        $leaked = 0;

        // Act
        foreach ($names as $name) {
            try {
                if (str_contains($this->views->render($name), 'SECRET')) {
                    ++$leaked;
                }
            } catch (Throwable) {
                // Expected: refused.
            }
        }

        // Assert
        self::assertSame(0, $leaked);
    }
}
