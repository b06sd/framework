<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Tusk\Loader;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Tests\Support\ViewHarness;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\Exception\TemplateNotFoundException;
use Trunk\Tusk\Loader\CompiledTemplateLoader;
use Trunk\Tusk\Loader\ConfiguredTemplateLoader;
use Trunk\Tusk\Loader\SourceTemplateLoader;
use Trunk\Tusk\Renderer;

final class LoadersTest extends TestCase
{
    private ViewHarness $views;

    protected function setUp(): void
    {
        $this->views = new ViewHarness(['home' => 'Hello {{ name }}', 'users/show' => 'User {{ id }}']);
    }

    protected function tearDown(): void
    {
        $this->views->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'parent traversal' => ['../home'];
        yield 'embedded traversal' => ['users/../home'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'nul byte' => ["home\0.php"];
        yield 'double slash' => ['users//show'];
        yield 'empty' => [''];
        yield 'extension smuggling' => ['home.tusk.php'];
        yield 'backslash' => ['users\\show'];
    }

    #[DataProvider('invalidNames')]
    public function test_the_source_loader_rejects_names_that_are_not_logical_template_paths(string $name): void
    {
        // Arrange
        $loader = new SourceTemplateLoader([$this->views->root], $this->views->cache);

        // Act & Assert
        $this->expectException(TemplateNotFoundException::class);
        $loader->load($name);
    }

    public function test_a_missing_template_is_reported(): void
    {
        // Arrange
        $loader = new SourceTemplateLoader([$this->views->root], $this->views->cache);

        // Act & Assert
        $this->expectException(TemplateNotFoundException::class);
        $loader->load('nope');
    }

    public function test_a_symlink_that_escapes_the_view_root_is_not_followed(): void
    {
        // Arrange
        $outside = $this->views->base . '/outside';
        mkdir($outside);
        file_put_contents($outside . '/secret.tusk.php', 'TOP SECRET');
        symlink($outside, $this->views->root . '/link');
        $loader = new SourceTemplateLoader([$this->views->root], $this->views->cache);

        // Act & Assert
        $this->expectException(TemplateNotFoundException::class);
        $loader->load('link/secret');
    }

    public function test_editing_a_source_file_is_picked_up_by_a_new_loader(): void
    {
        // Arrange
        $first = $this->views->render('home', ['name' => 'A']);
        $this->views->write('home', 'Changed and longer {{ name }}');

        // Act
        $second = $this->views->render('home', ['name' => 'A']);

        // Assert
        self::assertSame('Hello A', $first);
        self::assertSame('Changed and longer A', $second);
    }

    public function test_the_compiled_loader_serves_the_build_and_rejects_unknown_names(): void
    {
        // Arrange
        $build = $this->views->base . '/build';
        new TemplateBuilder()->build([$this->views->root], $build);
        $renderer = new Renderer(new CompiledTemplateLoader($build));

        // Act
        $output = $renderer->render('users/show', ['id' => 7]);

        // Assert
        self::assertSame('User 7', $output);
        $this->expectException(TemplateNotFoundException::class);
        $renderer->render('missing');
    }

    public function test_the_configured_loader_selects_its_mode_explicitly(): void
    {
        // Arrange
        $build = $this->views->base . '/build';
        new TemplateBuilder()->build([$this->views->root], $build);
        $development = new ConfiguredTemplateLoader(new Configuration(['views' => ['mode' => 'development', 'paths' => [$this->views->root], 'cache' => $this->views->cache]]));
        $compiled = new ConfiguredTemplateLoader(new Configuration(['views' => ['mode' => 'compiled', 'build' => $build]]));

        // Act
        $a = new Renderer($development)->render('home', ['name' => 'X']);
        $b = new Renderer($compiled)->render('home', ['name' => 'X']);

        // Assert
        self::assertSame('Hello X', $a);
        self::assertSame($a, $b);
    }

    public function test_an_unknown_mode_is_refused(): void
    {
        // Arrange
        $loader = new ConfiguredTemplateLoader(new Configuration(['views' => ['mode' => 'auto']]));

        // Act & Assert
        $this->expectException(TemplateNotFoundException::class);
        $loader->load('home');
    }
}
