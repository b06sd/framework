<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Trunk\Foundation\Configuration;
use Trunk\Http\Message\Response;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Middleware\SecurityHeadersMiddleware;
use Trunk\Http\Security\SecurityHeaders;

final class SecurityHeadersTest extends TestCase
{
    public function test_the_defaults_add_every_protective_header_and_hsts_only_over_https(): void
    {
        // Arrange
        $policy = new SecurityHeaders();

        // Act
        $http = $policy->apply(new Response(200), false);
        $https = $policy->apply(new Response(200), true);

        // Assert
        self::assertSame('nosniff', $http->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $http->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $http->getHeaderLine('Referrer-Policy'));
        self::assertSame('same-origin', $http->getHeaderLine('Cross-Origin-Opener-Policy'));
        self::assertSame('same-origin', $http->getHeaderLine('Cross-Origin-Resource-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $http->getHeaderLine('Permissions-Policy'));
        self::assertSame("frame-ancestors 'none'; base-uri 'self'; form-action 'self'", $http->getHeaderLine('Content-Security-Policy'));
        self::assertFalse($http->hasHeader('Strict-Transport-Security'), 'a plain-http server must never be pinned to https');
        self::assertSame('max-age=31536000; includeSubDomains', $https->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_a_header_the_handler_already_set_is_never_replaced(): void
    {
        // Arrange
        $response = new Response(200, ['X-Frame-Options' => 'SAMEORIGIN', 'Content-Security-Policy' => "default-src 'none'", 'Strict-Transport-Security' => 'max-age=1']);

        // Act
        $applied = new SecurityHeaders()->apply($response, true);

        // Assert
        self::assertSame('SAMEORIGIN', $applied->getHeaderLine('X-Frame-Options'));
        self::assertSame("default-src 'none'", $applied->getHeaderLine('Content-Security-Policy'));
        self::assertSame('max-age=1', $applied->getHeaderLine('Strict-Transport-Security'));
        self::assertSame('nosniff', $applied->getHeaderLine('X-Content-Type-Options'), 'the rest of the policy still applies');
    }

    public function test_configuration_can_override_omit_and_tune_hsts(): void
    {
        // Arrange
        $configuration = new Configuration(['http' => ['security_headers' => [
            'enabled' => true,
            'frame_options' => 'SAMEORIGIN',
            'referrer_policy' => 'no-referrer',
            'permissions_policy' => false,
            'content_security_policy' => "default-src 'self'",
            'hsts' => 63_072_000,
            'hsts_preload' => true,
        ]]]);

        // Act
        $policy = SecurityHeaders::fromConfiguration($configuration);
        $response = $policy?->apply(new Response(200), true);

        // Assert
        self::assertNotNull($response);
        self::assertSame('SAMEORIGIN', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('no-referrer', $response->getHeaderLine('Referrer-Policy'));
        self::assertFalse($response->hasHeader('Permissions-Policy'));
        self::assertSame("default-src 'self'", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('max-age=63072000; includeSubDomains; preload', $response->getHeaderLine('Strict-Transport-Security'));
    }

    public function test_hsts_can_be_turned_off_and_the_policy_is_null_unless_enabled(): void
    {
        // Act
        $off = SecurityHeaders::fromConfiguration(new Configuration(['http' => ['security_headers' => ['enabled' => true, 'hsts' => false]]]));

        // Assert
        self::assertFalse($off?->apply(new Response(200), true)->hasHeader('Strict-Transport-Security'));
        self::assertNull(SecurityHeaders::fromConfiguration(new Configuration([])));
        self::assertNull(SecurityHeaders::fromConfiguration(new Configuration(['http' => ['security_headers' => ['enabled' => false]]])));
        self::assertNull(SecurityHeaders::fromConfiguration(new Configuration(['http' => ['security_headers' => ['frame_options' => 'DENY']]])), 'a block without enabled = true changes nothing');
        self::assertTrue(SecurityHeaders::configured(new Configuration([]))->apply(new Response(200), false)->hasHeader('X-Frame-Options'), 'the middleware works from defaults when explicitly used');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'line break in a value' => [['referrer_policy' => "no-referrer\r\nSet-Cookie: x=y"], 'printable ASCII'];
        yield 'non ascii in a value' => [['content_security_policy' => "default-src 'self' é"], 'printable ASCII'];
        yield 'nul in a value' => [['permissions_policy' => "a\0b"], 'printable ASCII'];
        yield 'huge value' => [['content_security_policy' => str_repeat('a', 2049)], 'printable ASCII'];
        yield 'empty value' => [['referrer_policy' => ''], 'printable ASCII'];
        yield 'not a string' => [['referrer_policy' => 5], 'must be a string'];
        yield 'bad frame options' => [['frame_options' => 'ALLOW-FROM https://x.example'], 'DENY or SAMEORIGIN'];
        yield 'negative hsts' => [['hsts' => -1], 'two years'];
        yield 'too long hsts' => [['hsts' => 99_999_999_999], 'two years'];
        yield 'string hsts' => [['hsts' => 'forever'], 'number of seconds'];
        yield 'preload without subdomains' => [['hsts_preload' => true, 'hsts_include_subdomains' => false], 'preload needs'];
        yield 'preload with a short max age' => [['hsts_preload' => true, 'hsts' => 60], 'preload needs'];
        yield 'subdomains not a bool' => [['hsts_include_subdomains' => 'yes'], 'true or false'];
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
        SecurityHeaders::fromConfiguration(new Configuration(['http' => ['security_headers' => ['enabled' => true, ...$block]]]));
    }

    public function test_the_middleware_applies_the_policy_to_the_wrapped_routes_and_reads_https_from_the_request(): void
    {
        // Arrange
        $middleware = new SecurityHeadersMiddleware(new SecurityHeaders());
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        // Act
        $plain = $middleware->process(new ServerRequest('GET', 'http://app.test/'), $handler);
        $secure = $middleware->process(new ServerRequest('GET', 'https://app.test/'), $handler);

        // Assert
        self::assertSame('nosniff', $plain->getHeaderLine('X-Content-Type-Options'));
        self::assertFalse($plain->hasHeader('Strict-Transport-Security'));
        self::assertTrue($secure->hasHeader('Strict-Transport-Security'));
    }
}
