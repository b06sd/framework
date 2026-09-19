<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Compiler;

use PHPUnit\Framework\TestCase;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Compiler\RouteTable;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Exception\RouteCompilationException;
use Trunk\Tests\Fixtures\Controllers\UserController;

final class RouteCompilerTest extends TestCase
{
    public function test_static_routes_become_hash_entries_and_dynamic_routes_become_regexes(): void
    {
        // Arrange

        // Act
        $table = $this->compile(static function (RouteCollector $r): void {
            $r->get('/users', [UserController::class, 'index']);
            $r->get('/users/{id:int}', [UserController::class, 'show'], 'show');
        });

        // Assert
        self::assertSame(['/users' => 0], $table->static['GET']);
        self::assertCount(1, $table->dynamic['GET']);
        self::assertStringContainsString('/users/([0-9]+)(*MARK:1)', $table->dynamic['GET'][0]);
        self::assertSame(['show' => 1], $table->names);
        self::assertSame(['id'], $table->routes[1]['params']);
    }

    public function test_an_optional_tail_registers_both_shapes(): void
    {
        // Arrange

        // Act
        $table = $this->compile(static function (RouteCollector $r): void {
            $r->get('/blog/{page?}', [UserController::class, 'index']);
        });

        // Assert
        self::assertArrayHasKey('/blog', $table->static['GET']);
        self::assertCount(1, $table->dynamic['GET']);
    }

    public function test_many_dynamic_routes_are_split_into_chunks(): void
    {
        // Arrange

        // Act
        $table = $this->compile(static function (RouteCollector $r): void {
            for ($i = 0; $i < 65; ++$i) {
                $r->get('/r' . $i . '/{id}', [UserController::class, 'show']);
            }
        });

        // Assert
        self::assertCount(3, $table->dynamic['GET']);
    }

    public function test_duplicate_routes_and_names_are_reported_together(): void
    {
        // Arrange
        $routes = new RouteCollector();
        $routes->get('/a', [UserController::class, 'index'], 'same');
        $routes->get('/a', [UserController::class, 'show'], 'same');
        $routes->get('/u/{id}', [UserController::class, 'show']);
        $routes->get('/u/{name}', [UserController::class, 'show']);

        // Act
        try {
            new RouteCompiler()->compile($routes->routes());
            self::fail('Expected a RouteCompilationException.');
        } catch (RouteCompilationException $e) {
            // Assert
            self::assertCount(3, $e->errors);
            self::assertStringContainsString('Route name "same"', implode("\n", $e->errors));
        }
    }

    public function test_the_same_pattern_may_exist_for_different_methods(): void
    {
        // Arrange

        // Act
        $table = $this->compile(static function (RouteCollector $r): void {
            $r->get('/a', [UserController::class, 'index']);
            $r->post('/a', [UserController::class, 'show']);
        });

        // Assert
        self::assertSame(['GET', 'POST'], $table->methods());
    }
    /**
     * @param callable(RouteCollector): void $define
     */
    private function compile(callable $define): RouteTable
    {
        $routes = new RouteCollector();
        $define($routes);

        return new RouteCompiler()->compile($routes->routes());
    }
}
