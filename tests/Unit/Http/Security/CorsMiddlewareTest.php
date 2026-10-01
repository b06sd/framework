<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Security;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Foundation\Configuration;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Message\Response;
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

    public function test_with_a_list_of_origins_every_response_varies_by_origin_even_one_without_cors_headers(): void
    {
        // Arrange: a cache must not hand the plain answer to an allowed origin, or the CORS answer to anyone else
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com']]));

        // Act
        $none = $middleware->process(new ServerRequest('GET', 'https://api.example.com/x'), $this->handler());
        $refused = $middleware->process(new ServerRequest('GET', 'https://api.example.com/x', ['Origin' => 'https://evil.example.com']), $this->handler());
        $allowed = $middleware->process(new ServerRequest('GET', 'https://api.example.com/x', ['Origin' => 'https://app.example.com']), $this->handler());

        // Assert
        self::assertSame('Origin', $none->getHeaderLine('Vary'));
        self::assertSame('Origin', $refused->getHeaderLine('Vary'));
        self::assertSame('Origin', $allowed->getHeaderLine('Vary'));
    }

    public function test_any_origin_without_credentials_does_not_vary_since_every_origin_gets_the_same_answer(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['*']]));

        // Act
        $response = $middleware->process(new ServerRequest('GET', 'https://api.example.com/x', ['Origin' => 'https://app.example.com']), $this->handler());

        // Assert
        self::assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertFalse($response->hasHeader('Vary'), 'a needless Vary splits shared caches per origin');
    }

    public function test_what_the_response_already_varies_by_is_kept_not_replaced(): void
    {
        // Arrange: a session sets Vary: Cookie further in; losing it would let a cache share one visitor's page
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com']]));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Vary' => ['Cookie', 'origin']]);
            }
        };

        // Act
        $response = $middleware->process(new ServerRequest('GET', 'https://api.example.com/x', ['Origin' => 'https://app.example.com']), $handler);

        // Assert
        self::assertSame('Cookie, origin', $response->getHeaderLine('Vary'), 'merged once, case-insensitively');
    }

    public function test_a_preflight_that_echoes_the_requested_headers_varies_by_them(): void
    {
        // Arrange
        $middleware = $this->middleware($this->enabled(['allowed_origins' => ['https://app.example.com'], 'allowed_headers' => ['*']]));
        $request = new ServerRequest('OPTIONS', 'https://api.example.com/x', ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'X-Custom']);

        // Act
        $response = $middleware->process($request, $this->handler());

        // Assert
        self::assertSame('X-Custom', $response->getHeaderLine('Access-Control-Allow-Headers'));
        self::assertSame('Origin, Access-Control-Request-Headers', $response->getHeaderLine('Vary'));
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
