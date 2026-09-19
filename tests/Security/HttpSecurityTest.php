<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Http\Message\Request;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Uri\Uri;

final class HttpSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function injectionValues(): iterable
    {
        yield 'crlf' => ["x\r\nSet-Cookie: a=b"];
        yield 'lf' => ["x\nX-Injected: 1"];
        yield 'cr' => ["x\rX-Injected: 1"];
        yield 'nul' => ["x\0y"];
        yield 'control' => ["x\x01y"];
    }

    #[DataProvider('injectionValues')]
    public function test_header_values_with_control_characters_are_rejected_everywhere(string $value): void
    {
        // Arrange
        $attempts = [
            static fn(): Response => new Response(200, ['X-A' => $value]),
            static fn(): Response => new Response()->withHeader('X-A', $value),
            static fn(): Response => new Response()->withAddedHeader('X-A', [$value]),
            static fn(): Request => new Request('GET', '/', ['X-A' => $value]),
            static fn(): ServerRequest => new ServerRequest('GET', '/', ['X-A' => $value]),
        ];
        $rejected = 0;

        // Act
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($attempts), $rejected);
    }

    #[DataProvider('injectionValues')]
    public function test_header_names_methods_and_reason_phrases_with_control_characters_are_rejected(string $value): void
    {
        // Arrange
        $attempts = [
            static fn(): Response => new Response()->withHeader($value, 'x'),
            static fn(): Request => new Request($value, '/'),
            static fn(): Response => new Response()->withStatus(200, $value),
        ];
        $rejected = 0;

        // Act
        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($attempts), $rejected);
    }

    public function test_request_targets_with_whitespace_are_rejected(): void
    {
        // Arrange
        $request = new Request('GET', '/');

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $request->withRequestTarget("/ HTTP/1.1\r\nHost: evil");
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'zero' => [0];
        yield 'too low' => [99];
        yield 'too high' => [600];
        yield 'negative' => [-200];
    }

    #[DataProvider('invalidStatuses')]
    public function test_invalid_status_codes_are_rejected(int $status): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        new Response($status);
    }

    public function test_uri_components_cannot_smuggle_a_different_authority(): void
    {
        // Arrange
        $uri = new Uri('https://good.test/');

        // Act
        $attempts = [
            static fn(): Uri => $uri->withHost('good.test@evil.test'),
            static fn(): Uri => $uri->withHost('evil.test/'),
            static fn(): Uri => $uri->withPath("/\r\nHost: evil.test"),
            static fn(): Uri => $uri->withQuery("a=b\r\nX: y"),
            static fn(): Uri => new Uri('https://good.test\\@evil.test/'),
        ];
        $rejected = 0;

        foreach ($attempts as $attempt) {
            try {
                $attempt();
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($attempts), $rejected);
    }
}
