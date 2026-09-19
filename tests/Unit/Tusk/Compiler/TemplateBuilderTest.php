<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Tusk\Compiler;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ViewHarness;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\Exception\TemplateBuildException;
use Trunk\Tusk\Loader\CompiledTemplateLoader;
use Trunk\Tusk\Renderer;

final class TemplateBuilderTest extends TestCase
{
    private ViewHarness $views;

    protected function tearDown(): void
    {
        $this->views->cleanUp();
    }

    public function test_compiled_output_is_identical_to_development_output(): void
    {
        // Arrange
        $views = $this->views([
            'layouts/app' => '<title><slot name="t">x</slot></title><slot />',
            'partials/item' => '<li>{{ v|upper }}</li>',
            'page' => '<layout name="app"><fill slot="t">{{ title }}</fill><ul><for each="items" as="v"><include name="partials/item" v="v" /></for></ul></layout>',
        ]);
        $build = $views->base . '/build';
        $data = ['title' => 'Hi <b>', 'items' => ['a', 'b']];

        // Act
        new TemplateBuilder()->build([$views->root], $build);
        $compiled = new Renderer(new CompiledTemplateLoader($build))->render('page', $data);

        // Assert
        self::assertSame($views->render('page', $data), $compiled);
        self::assertSame('<title>Hi &lt;b&gt;</title><ul><li>A</li><li>B</li></ul>', $compiled);
        self::assertFileExists($build . '/views.php');
    }

    public function test_all_problems_are_reported_together_and_nothing_is_written(): void
    {
        // Arrange
        $views = $this->views([
            'syntax' => '{{ 1 + }}',
            'missing' => '<include name="not/there" />',
            'cycle-a' => '<include name="cycle-b" />',
            'cycle-b' => '<include name="cycle-a" />',
            'bad name' => 'x',
        ]);
        $build = $views->base . '/build';

        // Act
        try {
            new TemplateBuilder()->build([$views->root], $build);
            self::fail('Expected a TemplateBuildException.');
        } catch (TemplateBuildException $e) {
            // Assert
            $all = implode("\n", $e->errors);
            self::assertStringContainsString('syntax:1:', $all);
            self::assertStringContainsString('"not/there", which does not exist', $all);
            self::assertStringContainsString('Circular template reference: cycle-a -> cycle-b -> cycle-a', $all);
            self::assertStringContainsString('not a valid template file name', $all);
            self::assertDirectoryDoesNotExist($build);
        }
    }

    public function test_the_first_view_root_wins_for_duplicate_names(): void
    {
        // Arrange
        $views = $this->views(['home' => 'app version']);
        $override = new ViewHarness(['home' => 'vendor version', 'only-vendor' => 'v']);
        $build = $views->base . '/build';

        // Act
        new TemplateBuilder()->build([$views->root, $override->root], $build);
        $renderer = new Renderer(new CompiledTemplateLoader($build));
        $home = $renderer->render('home');
        $vendor = $renderer->render('only-vendor');
        $override->cleanUp();

        // Assert
        self::assertSame('app version', $home);
        self::assertSame('v', $vendor);
    }

    public function test_a_missing_view_directory_is_an_error(): void
    {
        // Arrange
        $views = $this->views([]);

        // Act & Assert
        $this->expectException(TemplateBuildException::class);
        new TemplateBuilder()->build([$views->base . '/nope'], $views->base . '/build');
    }

    /**
     * @param array<string, string> $templates
     */
    private function views(array $templates): ViewHarness
    {
        return $this->views = new ViewHarness($templates);
    }
}
