<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Configuration;
use Trunk\Http\Security\Cors;

final class CorsTest extends TestCase
{
    public function test_a_wildcard_origin_allows_anything_and_never_sends_credentials(): void
    {
        // Arrange
        $cors = new Cors();

        // Act & Assert
        self::assertTrue($cors->allows('https://anything.example'));
        self::assertSame('*', $cors->responseHeaders('https://anything.example')['Access-Control-Allow-Origin']);
        self::assertArrayNotHasKey('Access-Control-Allow-Credentials', $cors->responseHeaders('https://anything.example'));
    }

    public function test_an_exact_origin_list_only_allows_those_origins_and_echoes_the_asking_one(): void
    {
        // Arrange
        $cors = new Cors(allowedOrigins: ['https://app.example.com', 'https://admin.example.com']);

        // Act & Assert
        self::assertTrue($cors->allows('https://app.example.com'));
        self::assertFalse($cors->allows('https://evil.example.com'));
        self::assertFalse($cors->allows(''));
        self::assertSame('https://app.example.com', $cors->responseHeaders('https://app.example.com')['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $cors->responseHeaders('https://app.example.com')['Vary']);
    }

    public function test_credentials_add_the_header_and_need_an_exact_origin(): void
    {
        // Arrange
        $cors = new Cors(allowedOrigins: ['https://app.example.com'], allowCredentials: true);

        // Act
        $headers = $cors->responseHeaders('https://app.example.com');

        // Assert
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin'], 'credentials forbid the "*" shortcut, even if it were configured');
    }

    public function test_preflight_headers_include_methods_and_a_max_age(): void
    {
        // Arrange
        $cors = new Cors(allowedMethods: ['GET', 'POST'], allowedHeaders: ['X-Custom'], maxAge: 600);

        // Act
        $headers = $cors->preflightHeaders('https://app.example.com', 'X-Requested');

        // Assert
        self::assertSame('GET, POST', $headers['Access-Control-Allow-Methods']);
        self::assertSame('X-Custom', $headers['Access-Control-Allow-Headers'], 'a fixed list is sent as configured, not what was asked');
        self::assertSame('600', $headers['Access-Control-Max-Age']);
    }

    public function test_a_wildcard_allowed_headers_echoes_back_what_the_preflight_asked_for(): void
    {
        // Arrange
        $cors = new Cors(allowedHeaders: ['*']);

        // Act & Assert
        self::assertSame('X-One, X-Two', $cors->preflightHeaders('https://app.example.com', 'X-One, X-Two')['Access-Control-Allow-Headers']);
        self::assertSame('*', $cors->preflightHeaders('https://app.example.com', null)['Access-Control-Allow-Headers'], 'nothing was asked, so the configured wildcard is sent as-is');
    }

    public function test_exposed_headers_are_only_sent_when_configured(): void
    {
        // Arrange
        $none = new Cors();
        $some = new Cors(exposedHeaders: ['X-Request-Id']);

        // Act & Assert
        self::assertArrayNotHasKey('Access-Control-Expose-Headers', $none->responseHeaders('https://app.example.com'));
        self::assertSame('X-Request-Id', $some->responseHeaders('https://app.example.com')['Access-Control-Expose-Headers']);
    }

    public function test_disabled_unless_enabled_and_null_without_an_enabled_block(): void
    {
        // Act & Assert
        self::assertNull(Cors::fromConfiguration(new Configuration([])));
        self::assertNull(Cors::fromConfiguration(new Configuration(['http' => ['cors' => ['enabled' => false]]])));
        self::assertNull(Cors::fromConfiguration(new Configuration(['http' => ['cors' => ['allowed_origins' => ['https://x.example']]]])), 'a block without enabled = true changes nothing');
        self::assertInstanceOf(Cors::class, Cors::fromConfiguration(new Configuration(['http' => ['cors' => ['enabled' => true]]])));
        self::assertInstanceOf(Cors::class, Cors::configured(new Configuration([])), 'the middleware can build one from defaults when explicitly used');
    }

    public function test_configuration_reads_every_setting(): void
    {
        // Arrange
        $configuration = new Configuration(['http' => ['cors' => [
            'enabled' => true,
            'allowed_origins' => ['https://app.example.com'],
            'allowed_methods' => ['GET'],
            'allowed_headers' => ['X-Custom'],
            'exposed_headers' => ['X-Request-Id'],
            'allow_credentials' => true,
            'max_age' => 60,
        ]]]);

        // Act
        $cors = Cors::fromConfiguration($configuration);

        // Assert
        self::assertNotNull($cors);
        self::assertTrue($cors->allows('https://app.example.com'));
        self::assertFalse($cors->allows('https://other.example.com'));
        $headers = $cors->preflightHeaders('https://app.example.com', null);
        self::assertSame('GET', $headers['Access-Control-Allow-Methods']);
        self::assertSame('X-Custom', $headers['Access-Control-Allow-Headers']);
        self::assertSame('60', $headers['Access-Control-Max-Age']);
        self::assertSame('true', $headers['Access-Control-Allow-Credentials']);
        self::assertSame('X-Request-Id', $cors->responseHeaders('https://app.example.com')['Access-Control-Expose-Headers']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no origins' => [['allowed_origins' => []], 'at least one origin'];
        yield 'origin with a path' => [['allowed_origins' => ['https://app.example.com/api']], 'no path'];
        yield 'origin without scheme' => [['allowed_origins' => ['app.example.com']], 'no path'];
        yield 'ftp origin' => [['allowed_origins' => ['ftp://app.example.com']], 'no path'];
        yield 'wildcard with credentials' => [['allowed_origins' => ['*'], 'allow_credentials' => true], 'cannot be combined'];
        yield 'lowercase method' => [['allowed_methods' => ['get']], 'uppercase HTTP method'];
        yield 'header with a space' => [['allowed_headers' => ['X Custom']], 'plain header name'];
        yield 'exposed header with a newline' => [['exposed_headers' => ["X-A\r\nX-B"]], 'plain header name'];
        yield 'negative max age' => [['max_age' => -1], 'between 0 and'];
        yield 'huge max age' => [['max_age' => 999_999_999], 'between 0 and'];
        yield 'origins not a list' => [['allowed_origins' => ['a' => 'https://x.example']], 'list of strings'];
        yield 'allow_credentials not a bool' => [['allow_credentials' => 'yes'], 'true or false'];
    }

    /**
     * @param array<string, mixed> $block
     */
    #[DataProvider('invalid')]
    public function test_a_bad_value_is_refused_with_a_message_naming_the_setting(array $block, string $expected): void
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expected);
        Cors::fromConfiguration(new Configuration(['http' => ['cors' => ['enabled' => true, ...$block]]]));
    }
}
