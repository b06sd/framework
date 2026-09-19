<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Routing;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Routing\RoutingMiddleware;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Matcher\Matcher;
use Trunk\Router\Matcher\MatchResult;
use Trunk\Tests\Fixtures\Controllers\UserController;
use Trunk\Tests\Support\CapturingHandler;

final class RoutingMiddlewareTest extends TestCase
{
    public function test_a_match_is_attached_to_the_request(): void
    {
        // Arrange
        $handler = new CapturingHandler();

        // Act
        $this->middleware()->process(new ServerRequest('GET', '/users/5'), $handler);

        // Assert
        self::assertNotNull($handler->seen);
        $match = $handler->seen->getAttribute(RoutingMiddleware::ATTRIBUTE);
        self::assertInstanceOf(MatchResult::class, $match);
        self::assertSame(['id' => '5'], $match->params);
    }

    public function test_unmatched_paths_raise_404(): void
    {
        // Arrange
        $middleware = $this->middleware();

        // Act & Assert
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(404);
        $middleware->process(new ServerRequest('GET', '/nope'), new CapturingHandler());
    }

    public function test_a_wrong_method_raises_405_with_allow(): void
    {
        // Arrange
        $middleware = $this->middleware();

        // Act
        try {
            $middleware->process(new ServerRequest('POST', '/users/5'), new CapturingHandler());
            self::fail('Expected HttpException.');
        } catch (HttpException $e) {
            // Assert
            self::assertSame(405, $e->statusCode);
            self::assertSame(['Allow' => 'GET, HEAD'], $e->headers);
        }
    }

    private function middleware(): RoutingMiddleware
    {
        $r = new RouteCollector();
        $r->get('/users/{id:int}', [UserController::class, 'show']);

        return new RoutingMiddleware(new Matcher(new RouteCompiler()->compile($r->routes())));
    }
}
