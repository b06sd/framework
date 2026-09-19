<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Url;

use PHPUnit\Framework\TestCase;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Exception\RouteNotFoundException;
use Trunk\Router\Url\UrlGenerator;
use Trunk\Tests\Fixtures\Controllers\UserController;

final class UrlGeneratorTest extends TestCase
{
    public function test_urls_are_built_from_names_and_parameters(): void
    {
        // Arrange
        $urls = $this->generator();

        // Act & Assert
        self::assertSame('/', $urls->generate('home'));
        self::assertSame('/users/42', $urls->generate('user', ['id' => 42]));
        self::assertSame('/blog', $urls->generate('blog'));
        self::assertSame('/blog/2', $urls->generate('blog', ['page' => '2']));
    }

    public function test_values_are_percent_encoded_and_catch_all_keeps_its_slashes(): void
    {
        // Arrange
        $urls = $this->generator();

        // Act & Assert
        self::assertSame('/tags/a%20b%2Fc', $urls->generate('tag', ['tag' => 'a b/c']));
        self::assertSame('/files/a%20b/c.txt', $urls->generate('files', ['path' => 'a b/c.txt']));
    }

    public function test_unused_parameters_and_explicit_query_become_the_query_string(): void
    {
        // Arrange
        $urls = $this->generator();

        // Act
        $url = $urls->generate('user', ['id' => 1, 'ref' => 'a b'], ['page' => 2]);

        // Assert
        self::assertSame('/users/1?ref=a%20b&page=2', $url);
    }

    public function test_missing_parameters_and_constraint_violations_are_rejected(): void
    {
        // Arrange
        $urls = $this->generator();
        $rejected = 0;

        // Act
        foreach ([[], ['id' => 'abc'], ['id' => '1/2']] as $params) {
            try {
                $urls->generate('user', $params);
            } catch (InvalidRouteException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(3, $rejected);
    }

    public function test_unknown_route_names_are_rejected(): void
    {
        // Arrange
        $urls = $this->generator();

        // Act & Assert
        $this->expectException(RouteNotFoundException::class);
        $urls->generate('nope');
    }
    private function generator(): UrlGenerator
    {
        $r = new RouteCollector();
        $r->get('/', [UserController::class, 'index'], 'home');
        $r->get('/users/{id:int}', [UserController::class, 'show'], 'user');
        $r->get('/tags/{tag}', [UserController::class, 'show'], 'tag');
        $r->get('/blog/{page?}', [UserController::class, 'index'], 'blog');
        $r->get('/files/{path:any}', [UserController::class, 'show'], 'files');

        return new UrlGenerator(new RouteCompiler()->compile($r->routes()));
    }
}
