<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\RateLimit;

use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\RateLimit\Middleware\RateLimitMiddleware;
use Trunk\RateLimit\RateLimiter;
use Trunk\Tests\Support\CapturingHandler;
use Trunk\Tests\Support\DatabaseHarness;
use Trunk\Tests\Support\FixedClock;

final class RateLimitMiddlewareTest extends TestCase
{
    private RateLimiter $limiter;

    protected function setUp(): void
    {
        $connection = new DatabaseHarness()->sqlite();
        $connection->execute('CREATE TABLE trunk_rate_limits (key_hash VARCHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, window_start INTEGER NOT NULL)');
        $this->limiter = new RateLimiter($connection, 'trunk_rate_limits', new FixedClock());
    }

    public function test_requests_below_the_limit_reach_the_handler(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware($this->limiter, 2, 60);
        $handler = new CapturingHandler();
        $request = new ServerRequest('GET', 'https://api.example.com/x')->withAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE, '203.0.113.1');

        // Act
        $middleware->process($request, $handler);
        $middleware->process($request, $handler);

        // Assert: no exception, the handler ran both times
        self::assertNotNull($handler->seen);
    }

    public function test_a_request_over_the_limit_is_refused_before_reaching_the_handler(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware($this->limiter, 1, 60);
        $handler = new CapturingHandler();
        $request = new ServerRequest('GET', 'https://api.example.com/x')->withAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE, '203.0.113.1');
        $middleware->process($request, $handler);

        // Act & Assert
        try {
            $middleware->process($request, new CapturingHandler());
            self::fail('expected an HttpException');
        } catch (HttpException $e) {
            self::assertSame(429, $e->statusCode);
        }
    }

    public function test_different_client_addresses_have_separate_budgets(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware($this->limiter, 1, 60);
        $first = new ServerRequest('GET', 'https://api.example.com/x')->withAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE, '203.0.113.1');
        $second = new ServerRequest('GET', 'https://api.example.com/x')->withAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE, '203.0.113.2');

        // Act
        $middleware->process($first, new CapturingHandler());
        $middleware->process($second, new CapturingHandler());

        // Assert: no exception on the second address
        $this->expectNotToPerformAssertions();
    }

    public function test_a_request_with_no_known_address_is_still_limited_as_one_shared_bucket(): void
    {
        // Arrange
        $middleware = new RateLimitMiddleware($this->limiter, 1, 60);
        $request = new ServerRequest('GET', 'https://api.example.com/x');
        $middleware->process($request, new CapturingHandler());

        // Act & Assert
        $this->expectException(HttpException::class);
        $middleware->process($request, new CapturingHandler());
    }
}
