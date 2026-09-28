<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Security;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Middleware\CorsMiddleware;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Tests\Support\CapturingHandler;

final class CorsMiddlewareTest extends TestCase
{
    public function test_disabled_by_default_never_touches_the_request_or_response(): void
    {
        // Arrange
        $middleware = $this->middleware(new Configuration([]));
        $handler = $this->handler();

        // Act
        $response = $middleware->process(new ServerRequest('OPTIONS', 'https://api.example.com/x', ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET']), $handler);

        // Assert
        self::assertNotNull($handler->seen);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_a_preflight_from_an_allowed_origin_is_answered_without_reaching_routing(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com']]));
        $handler = $this->handler();
        $request = new ServerRequest('OPTIONS', 'https://api.example.com/x', ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'Content-Type']);

        // Act
        $response = $middleware->process($request, $handler);

        // Assert
        self::assertNull($handler->seen, 'a preflight never reaches routing or the controller');
        self::assertSame(204, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function test_a_preflight_from_a_disallowed_origin_falls_through_unchanged(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com']]));
        $handler = $this->handler();
        $request = new ServerRequest('OPTIONS', 'https://api.example.com/x', ['Origin' => 'https://evil.example.com', 'Access-Control-Request-Method' => 'POST']);

        // Act
        $response = $middleware->process($request, $handler);

        // Assert
        self::assertNotNull($handler->seen, 'left to the router, which answers 404/405 as it always did');
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    public function test_a_real_request_gets_the_headers_added_to_the_real_response(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com'], 'exposed_headers' => ['X-Request-Id']]));
        $handler = $this->handler();
        $request = new ServerRequest('GET', 'https://api.example.com/x', ['Origin' => 'https://app.example.com']);

        // Act
        $response = $middleware->process($request, $handler);

        // Assert
        self::assertNotNull($handler->seen);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://app.example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertSame('X-Request-Id', $response->getHeaderLine('Access-Control-Expose-Headers'));
    }

    public function test_an_options_request_with_no_preflight_header_is_not_treated_as_a_preflight(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com']]));
        $handler = $this->handler();
        $request = new ServerRequest('OPTIONS', 'https://api.example.com/x', ['Origin' => 'https://app.example.com']);

        // Act
        $response = $middleware->process($request, $handler);

        // Assert
        self::assertNotNull($handler->seen, 'no Access-Control-Request-Method means this is a plain OPTIONS request, not a preflight');
        self::assertSame(200, $response->getStatusCode());
    }

    public function test_a_request_with_no_origin_header_is_untouched(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled([]));
        $handler = $this->handler();

        // Act
        $response = $middleware->process(new ServerRequest('GET', 'https://api.example.com/x'), $handler);

        // Assert
        self::assertNotNull($handler->seen);
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    /**
     * @param array<string, mixed> $cors
     */
    private function enabled(array $cors): Configuration
    {
        return new Configuration(['http' => ['cors' => ['enabled' => true, ...$cors]]]);
    }

    private function middleware(Configuration $configuration): CorsMiddleware
    {
        return new CorsMiddleware($configuration, new ResponseBuilder(new HttpFactory()));
    }

    private function handler(): CapturingHandler
    {
        return new CapturingHandler();
    }
}
