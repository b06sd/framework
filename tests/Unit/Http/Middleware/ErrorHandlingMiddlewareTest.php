<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;
use Trunk\Http\Exception\HttpException;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Middleware\ErrorHandlingMiddleware;
use Trunk\Tests\Support\RecordingLogger;

final class ErrorHandlingMiddlewareTest extends TestCase
{
    public function test_http_exceptions_become_their_status_with_the_standard_reason_phrase(): void
    {
        // Arrange
        $middleware = new ErrorHandlingMiddleware(new HttpFactory());

        // Act
        $response = $middleware->process(new ServerRequest('GET', '/'), $this->failing(HttpException::methodNotAllowed(['GET'])));

        // Assert
        self::assertSame(405, $response->getStatusCode());
        self::assertSame('GET', $response->getHeaderLine('Allow'));
        self::assertSame('Method Not Allowed', (string) $response->getBody());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function test_unexpected_errors_become_a_generic_500_and_are_logged(): void
    {
        // Arrange
        $logger = new RecordingLogger();
        $middleware = new ErrorHandlingMiddleware(new HttpFactory(), false, $logger);

        // Act
        $response = $middleware->process(new ServerRequest('GET', '/'), $this->failing(new RuntimeException('secret database password')));

        // Assert
        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Internal Server Error', (string) $response->getBody());
        self::assertSame([['error', 'Unhandled exception while handling the request.']], $logger->records);
    }

    public function test_a_broken_logger_does_not_change_the_response(): void
    {
        // Arrange
        $middleware = new ErrorHandlingMiddleware(new HttpFactory(), false, new RecordingLogger(throws: true));

        // Act
        $response = $middleware->process(new ServerRequest('GET', '/'), $this->failing(new RuntimeException('x')));

        // Assert
        self::assertSame(500, $response->getStatusCode());
    }

    public function test_debug_mode_includes_the_exception_details(): void
    {
        // Arrange
        $middleware = new ErrorHandlingMiddleware(new HttpFactory(), true);

        // Act
        $body = (string) $middleware->process(new ServerRequest('GET', '/'), $this->failing(new RuntimeException('visible detail')))->getBody();

        // Assert
        self::assertStringContainsString('RuntimeException: visible detail', $body);
    }

    public function test_successful_responses_pass_through_untouched(): void
    {
        // Arrange
        $middleware = new ErrorHandlingMiddleware(new HttpFactory());
        $ok = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \Trunk\Http\Message\Response(201);
            }
        };

        // Act
        $response = $middleware->process(new ServerRequest('GET', '/'), $ok);

        // Assert
        self::assertSame(201, $response->getStatusCode());
    }

    public function test_debug_output_for_a_deliberate_http_error_has_no_stack_trace(): void
    {
        // Arrange
        $middleware = new ErrorHandlingMiddleware(new HttpFactory(), true);

        // Act
        $body = (string) $middleware->process(new ServerRequest('GET', '/'), $this->failing(HttpException::notFound('No such customer.')))->getBody();

        // Assert
        self::assertSame('404: No such customer.', $body);
    }
    private function failing(Throwable $error): RequestHandlerInterface
    {
        return new class ($error) implements RequestHandlerInterface {
            public function __construct(private readonly Throwable $error) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->error;
            }
        };
    }
}
