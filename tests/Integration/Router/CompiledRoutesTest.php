<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Router;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Router\Compiler\RouteArtifactWriter;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Compiler\RouteTable;
use Trunk\Router\Matcher\Matcher;
use Trunk\Tests\Fixtures\Modules\AlphaModule;
use Trunk\Tests\Fixtures\Modules\RoutedModule;
use Trunk\Tests\Support\ForbiddenConstructScanner;

final class CompiledRoutesTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-routes-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map(\unlink(...), glob($this->directory . '/*') ?: []);
        @rmdir($this->directory);
    }

    public function test_the_compiled_table_matches_exactly_like_the_in_memory_table(): void
    {
        // Arrange
        $manifest = new ModuleManifest([AlphaModule::class, RoutedModule::class]);
        new RouteArtifactWriter()->write($manifest, $this->directory);
        $compiled = new Matcher(RouteTable::fromFile($this->directory . '/routes.php'));
        $memory = new Matcher(new RouteCompiler()->compileProviders([new RoutedModule()]));
        $matrix = [
            ['GET', '/'], ['GET', '/users'], ['GET', '/users/me'], ['GET', '/users/12'], ['POST', '/users/12'],
            ['DELETE', '/users/12'], ['GET', '/users/abc'], ['GET', '/blog'], ['GET', '/blog/4'],
            ['GET', '/files/a/b/c'], ['HEAD', '/users/12'], ['GET', "/it's/\$special"], ['GET', '/missing'],
        ];

        // Act & Assert
        foreach ($matrix as [$method, $path]) {
            self::assertEquals($memory->match($method, $path), $compiled->match($method, $path), $method . ' ' . $path);
        }

        self::assertSame(['id' => '12'], $compiled->match('GET', '/users/12')->params);
    }

    public function test_the_generated_file_contains_no_dangerous_constructs(): void
    {
        // Arrange
        new RouteArtifactWriter()->write(new ModuleManifest([RoutedModule::class]), $this->directory);

        // Act
        $violations = new ForbiddenConstructScanner()->scan((string) file_get_contents($this->directory . '/routes.php'), 'routes.php');

        // Assert
        self::assertSame([], $violations);
    }

    public function test_modules_without_routes_produce_an_empty_but_valid_table(): void
    {
        // Arrange
        new RouteArtifactWriter()->write(new ModuleManifest([AlphaModule::class]), $this->directory);

        // Act
        $result = new Matcher(RouteTable::fromFile($this->directory . '/routes.php'))->match('GET', '/');

        // Assert
        self::assertFalse($result->isFound());
    }
}
