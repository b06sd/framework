<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Error;

use InvalidArgumentException;
use LogicException;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;
use Trunk\Container\Exception\ContainerException;
use Trunk\Database\Exception\QueryException;
use Trunk\Error\ErrorCode;
use Trunk\Error\ErrorContext;
use Trunk\Error\ErrorReport;
use Trunk\Error\ErrorReporter;
use Trunk\Error\ExceptionHandler;
use Trunk\Error\PublicError;
use Trunk\Error\ValidationException;
use Trunk\Http\Exception\HttpException;

final class ExceptionHandlerTest extends TestCase
{
    public function test_only_public_errors_are_described_to_the_client_everything_else_is_internal(): void
    {
        // Arrange
        $handler = new ExceptionHandler();
        $secret = new RuntimeException('SQLSTATE[HY000] password=hunter2 at /var/www/app/Repo.php');
        $notFound = new class ('Customer was not found.') extends RuntimeException implements PublicError {
            public function errorCode(): string
            {
                return 'CUSTOMER_NOT_FOUND';
            }

            public function statusCode(): int
            {
                return 404;
            }

            public function publicMessage(): string
            {
                return $this->getMessage();
            }

            public function details(): array
            {
                return [];
            }
        };

        // Act
        $internal = $handler->handle($secret, new ErrorContext(requestId: 'req_1'));
        $public = $handler->handle($notFound, new ErrorContext(requestId: 'req_2'));

        // Assert
        self::assertSame([500, 'INTERNAL_ERROR', 'An unexpected error occurred.', false, 'req_1'], [$internal->status, $internal->code, $internal->message, $internal->public, $internal->requestId]);
        self::assertStringNotContainsString('hunter2', $internal->message . $internal->code);
        self::assertSame([404, 'CUSTOMER_NOT_FOUND', 'Customer was not found.', true, 'notice'], [$public->status, $public->code, $public->message, $public->public, $public->level]);
    }

    public function test_http_and_validation_exceptions_are_public_with_safe_messages_and_stable_codes(): void
    {
        // Arrange
        $handler = new ExceptionHandler();

        // Act
        $notFound = $handler->handle(HttpException::notFound('the secret internal reason'));
        $route = $handler->handle(HttpException::notFound('', ErrorCode::RouteNotFound->value));
        $method = $handler->handle(HttpException::methodNotAllowed(['GET']));
        $validation = $handler->handle(new ValidationException(['email' => ['is required']]));
        $teapot = $handler->handle(new HttpException(503));

        // Assert
        self::assertSame([404, 'NOT_FOUND', 'Not Found'], [$notFound->status, $notFound->code, $notFound->message]);
        self::assertSame('ROUTE_NOT_FOUND', $route->code);
        self::assertSame('METHOD_NOT_ALLOWED', $method->code);
        self::assertSame([422, 'VALIDATION_FAILED', ['fields' => ['email' => ['is required']]]], [$validation->status, $validation->code, $validation->details]);
        self::assertSame(['error', 'INTERNAL_ERROR'], [$teapot->level, $teapot->code]);
    }

    public function test_internal_error_codes_are_kept_for_logs_not_for_clients(): void
    {
        // Arrange
        $handler = new ExceptionHandler();

        // Act
        $container = $handler->handle(new ContainerException('Cannot resolve "X". Fix: bind it.'));
        $database = $handler->handle(new QueryException('SELECT 1', '23000', 'integrity constraint violation'));

        // Assert
        self::assertSame(['INTERNAL_ERROR', 'DEPENDENCY_NOT_FOUND'], [$container->code, $container->internalCode]);
        self::assertSame(['INTERNAL_ERROR', 'DATABASE_ERROR'], [$database->code, $database->internalCode]);
    }

    public function test_debug_detail_exists_only_when_debug_is_on_and_never_contains_arguments(): void
    {
        // Arrange
        $handler = new ExceptionHandler();
        $throwing = static function (string $password): never {
            throw new ContainerException('Cannot resolve "Foo". Fix: register it.');
        };

        try {
            $throwing('hunter2-argument');
        } catch (Throwable $e) {
            $error = $e;
        }

        // Act
        $production = $handler->handle($error, new ErrorContext(debug: false));
        $development = $handler->handle($error, new ErrorContext(debug: true));

        // Assert
        self::assertNull($production->debug);
        self::assertNotNull($development->debug);
        self::assertSame('register it.', $development->debug->hint);
        self::assertSame(ContainerException::class, $development->debug->class);
        self::assertNotEmpty($development->debug->snippet);
        self::assertContains(true, array_column($development->debug->snippet, 'current'));
        self::assertStringNotContainsString('hunter2-argument', implode("\n", $development->debug->trace));
    }

    public function test_errors_are_logged_with_level_status_and_context(): void
    {
        // Arrange
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $handler = new ExceptionHandler($logger);

        // Act
        $handler->handle(HttpException::notFound(), new ErrorContext('http', 'req_9', 'trace_9'));
        $handler->handle(new LogicException('bug'), new ErrorContext('job', 'job_3'));

        // Assert
        self::assertSame('notice', $logger->records[0][0]);
        self::assertSame('error', $logger->records[1][0]);
        self::assertSame(['NOT_FOUND', 404, 'http', 'req_9', 'trace_9'], [$logger->records[0][2]['errorCode'], $logger->records[0][2]['status'], $logger->records[0][2]['kind'], $logger->records[0][2]['requestId'], $logger->records[0][2]['traceId']]);
        self::assertInstanceOf(LogicException::class, $logger->records[1][2]['exception']);
        self::assertSame('job', $logger->records[1][2]['kind']);
    }

    public function test_a_failing_logger_or_reporter_never_becomes_a_second_error_and_other_reporters_still_run(): void
    {
        // Arrange
        $failingLogger = new class extends AbstractLogger {
            public function log($level, string|Stringable $message, array $context = []): void
            {
                throw new PDOException('the log disk is full');
            }
        };
        $seen = [];
        $bad = new class implements ErrorReporter {
            public function report(Throwable $error, ErrorReport $report): void
            {
                throw new RuntimeException('tracker down');
            }
        };
        $good = new class ($seen) implements ErrorReporter {
            /** @param list<string> $seen */
            public function __construct(public array &$seen) {}

            public function report(Throwable $error, ErrorReport $report): void
            {
                $this->seen[] = $report->code;
            }
        };

        // Act
        $report = new ExceptionHandler($failingLogger, [$bad, $good])->handle(new RuntimeException('x'));

        // Assert
        self::assertSame(500, $report->status);
        self::assertSame(['INTERNAL_ERROR'], $seen);
    }

    public function test_the_http_exception_validates_its_code_and_status(): void
    {
        // Arrange
        $rejected = 0;

        // Act
        foreach ([[200], [399], [600]] as $args) {
            try {
                new HttpException(...$args);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        foreach (['lowercase', 'HAS SPACE', "INJECT\nED", ''] as $code) {
            try {
                new HttpException(400, code: $code);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(7, $rejected);
    }
}
