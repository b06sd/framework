<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Exception\HttpException;

final class HttpExceptionTest extends TestCase
{
    public function test_named_constructors_set_status_and_headers(): void
    {
        // Arrange

        // Act
        $notAllowed = HttpException::methodNotAllowed(['GET', 'HEAD']);

        // Assert
        self::assertSame(404, HttpException::notFound()->statusCode);
        self::assertSame(400, HttpException::badRequest()->statusCode);
        self::assertSame(405, $notAllowed->statusCode);
        self::assertSame(['Allow' => 'GET, HEAD'], $notAllowed->headers);
    }

    public function test_only_error_statuses_are_accepted(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new HttpException(200);
    }
}
