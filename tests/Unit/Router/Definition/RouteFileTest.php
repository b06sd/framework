<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Definition;

use PHPUnit\Framework\TestCase;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteFile;
use Trunk\Router\Exception\InvalidRouteException;

final class RouteFileTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-routefile-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function test_a_route_file_returning_a_function_is_loaded_and_defines_routes(): void
    {
        // Arrange
        file_put_contents($this->directory . '/web.php', "<?php\nuse Trunk\\Router\\Definition\\RouteCollector;\nreturn static function (RouteCollector \$routes): void { \$routes->get('/', ['Trunk\\\\Tests\\\\Fixtures\\\\Controllers\\\\UserController', 'index']); };\n");
        $routes = new RouteCollector();

        // Act
        RouteFile::load($this->directory . '/web.php')($routes);

        // Assert
        self::assertCount(1, $routes->routes());
    }

    public function test_a_missing_file_and_a_file_returning_something_else_are_explained(): void
    {
        // Arrange
        file_put_contents($this->directory . '/bad.php', "<?php\nreturn ['not', 'a', 'function'];\n");

        // Act and assert
        foreach (['nope.php' => 'does not exist', 'bad.php' => 'must return a function'] as $file => $expected) {
            try {
                RouteFile::load($this->directory . '/' . $file);
                self::fail('expected an InvalidRouteException for ' . $file);
            } catch (InvalidRouteException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
                self::assertStringNotContainsString($this->directory, $e->getMessage(), 'only the file name, not the path');
            }
        }
    }
}
