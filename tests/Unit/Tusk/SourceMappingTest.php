<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Tusk;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Error\DebugInfoFactory;
use Trunk\Tests\Support\ViewHarness;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\Compiler\TemplateCompiler;
use Trunk\Tusk\Exception\TemplateRuntimeException;
use Trunk\Tusk\Loader\CompiledTemplateLoader;
use Trunk\Tusk\Renderer;

/**
 * A runtime template error names the template file and line, not the generated PHP, in source and
 * compiled modes and through includes and layouts.
 */
final class SourceMappingTest extends TestCase
{
    private ?ViewHarness $views = null;

    protected function tearDown(): void
    {
        $this->views?->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'source' => ['source'];
        yield 'compiled' => ['compiled'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_an_undefined_variable_reports_the_template_and_line(string $mode): void
    {
        // Arrange & Act
        [$message, $line] = $this->failure(['users/show' => "<h1>Users</h1>\n<if test=\"show\">\n  <p>{{ user.name }}</p>\n</if>\n"], 'users/show', $mode);

        // Assert
        self::assertSame(3, $line);
        self::assertStringContainsString('at users/show.tusk.php:3', $message);
        self::assertStringContainsString('Undefined variable "user"', $message);
        self::assertStringNotContainsString('.php:' . 0, $message);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failure_inside_an_include_names_the_included_template_not_the_outer_one(string $mode): void
    {
        // Arrange & Act
        [$message, $line] = $this->failure([
            'page' => "<h1>Page</h1>\n<for each=\"items\" as=\"i\">\n<include name=\"partials/row\" i=\"i\" />\n</for>\n",
            'partials/row' => "<li>ok</li>\n<li>{{ missing }}</li>\n",
        ], 'page', $mode);

        // Assert
        self::assertSame(2, $line);
        self::assertStringContainsString('at partials/row.tusk.php:2', $message);
        self::assertStringContainsString('Error while rendering "page"', $message);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modes')]
    public function test_a_failure_in_a_layout_fill_reports_the_line_of_the_fill_content(string $mode): void
    {
        // Arrange & Act
        [$message, $line] = $this->failure([
            'layouts/app' => "<title><slot name=\"t\">x</slot></title>\n<slot />\n",
            'page' => "<layout name=\"app\">\n<fill slot=\"t\">Title</fill>\n\n<p>{{ nothing.here }}</p>\n</layout>\n",
        ], 'page', $mode);

        // Assert
        self::assertSame(4, $line);
        self::assertStringContainsString('page.tusk.php:4', $message);
    }

    public function test_the_development_error_info_shows_the_template_source_around_the_failing_line(): void
    {
        // Arrange
        $views = $this->views = new ViewHarness(['users/show' => "<h1>Users</h1>\n<p>fine</p>\n<p>{{ user.name }}</p>\n<p>after</p>\n"]);

        try {
            $views->renderer()->render('users/show');
            self::fail('Expected a TemplateRuntimeException.');
        } catch (TemplateRuntimeException $e) {
            // Act
            $info = new DebugInfoFactory()->create($e);
        }

        // Assert
        self::assertSame(3, $info->line);
        self::assertStringEndsWith('users/show.tusk.php', $info->file);
        $current = array_values(array_filter($info->snippet, static fn(array $l): bool => $l['current']));
        self::assertSame('<p>{{ user.name }}</p>', $current[0]['code']);
        self::assertSame([1, 2, 3, 4], array_column($info->snippet, 'line'));
        self::assertStringNotContainsString('<?php', implode('', array_column($info->snippet, 'code')), 'the generated PHP is not shown');
    }

    public function test_a_compiled_build_has_no_sources_so_no_snippet_and_no_path_is_shown(): void
    {
        // Arrange
        $views = $this->views = new ViewHarness(['users/show' => "<p>{{ user.name }}</p>\n"]);
        $build = $views->base . '/build';
        new TemplateBuilder()->build([$views->root], $build);

        try {
            new Renderer(new CompiledTemplateLoader($build))->render('users/show');
            self::fail('Expected a TemplateRuntimeException.');
        } catch (TemplateRuntimeException $e) {
            // Act
            $sourceFile = $e->sourceFile();
        }

        // Assert
        self::assertNull($sourceFile);
    }

    public function test_the_generated_file_carries_line_markers_and_a_sanitised_template_header(): void
    {
        // Arrange
        $compiler = new TemplateCompiler();

        // Act
        $php = $compiler->compile("a\n{{ x }}\n<if test=\"y\">\n{{ z }}\n</if>\n", 'ok/name')->php;
        $hostile = $compiler->compile("{{ x }}\n", "evil\n<?php system('id'); //")->php;

        // Assert
        self::assertStringContainsString('// tusk-template: ok/name', $php);
        self::assertMatchesRegularExpression('/ \/\/ tusk:2$/m', $php);
        self::assertMatchesRegularExpression('/ \/\/ tusk:4$/m', $php);
        self::assertStringNotContainsString("\n<?php system", $hostile);
    }

    public function test_errors_that_are_not_from_a_template_keep_the_plain_message(): void
    {
        // Arrange & Act
        $error = TemplateRuntimeException::in('x', new RuntimeException('boom'));

        // Assert
        self::assertSame('Error while rendering "x": boom', $error->getMessage());
        self::assertNull($error->sourceLine);
    }

    /**
     * @param array<string, string> $templates
     *
     * @return array{string, int|null}
     */
    private function failure(array $templates, string $render, string $mode): array
    {
        $views = $this->views = new ViewHarness($templates);
        $renderer = $views->renderer();

        if ($mode === 'compiled') {
            $build = $views->base . '/build';
            new TemplateBuilder()->build([$views->root], $build);
            $renderer = new Renderer(new CompiledTemplateLoader($build));
        }

        try {
            $renderer->render($render, ['show' => true, 'items' => [1, 2]]);
            self::fail('Expected a TemplateRuntimeException.');
        } catch (TemplateRuntimeException $e) {
            return [$e->getMessage(), $e->sourceLine];
        }
    }
}
