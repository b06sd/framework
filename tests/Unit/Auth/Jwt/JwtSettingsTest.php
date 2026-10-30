<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth\Jwt;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Auth\Jwt\JwtSettings;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\EnvSecret;

final class JwtSettingsTest extends TestCase
{
    public function test_the_key_set_url_defaults_to_the_issuers_well_known_path(): void
    {
        // Act
        $settings = JwtSettings::fromConfiguration(self::config([]));

        // Assert
        self::assertSame('https://id.example.com/.well-known/jwks.json', $settings->jwksUrl);
        self::assertSame(['RS256'], $settings->algorithms);
        self::assertSame(['sub', 'id'], [$settings->userClaim, $settings->userMatch]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function mistakes(): iterable
    {
        yield 'no issuer' => [['issuer' => ''], 'issuer is empty: set JWT_ISSUER'];
        yield 'no audience' => [['audience' => ''], 'audience is empty: set JWT_AUDIENCE'];
        yield 'alg none' => [['algorithms' => ['none']], '"none" is not supported'];
        yield 'unknown algorithm' => [['algorithms' => ['RS1']], '"RS1" is not supported'];
        yield 'plain http key set' => [['jwks_url' => 'http://id.example.com/jwks'], 'must be an https URL'];
        yield 'credentials in the url' => [['jwks_url' => 'https://user:pass@id.example.com/jwks'], 'must be an https URL'];
        yield 'HS without a secret' => [['algorithms' => ['HS256']], 'needs JWT_SECRET'];
        yield 'a short secret' => [['algorithms' => ['HS256'], 'secret' => 'short'], 'at least 32 bytes'];
        yield 'a secret nothing uses' => [['secret' => str_repeat('s', 40)], 'JWT_SECRET is set but algorithms lists no HS algorithm'];
        yield 'unknown user match' => [['user' => ['match' => 'email']], 'user.match "email" must be one of id, identifier, none'];
        yield 'leeway too long' => [['leeway' => 3600], 'leeway is between 0 and 300'];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('mistakes')]
    public function test_each_mistake_is_reported_with_its_fix(array $overrides, string $message): void
    {
        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        JwtSettings::fromConfiguration(self::config($overrides));
    }

    public function test_plain_http_is_allowed_for_a_provider_running_on_this_machine(): void
    {
        // Act
        $settings = JwtSettings::fromConfiguration(self::config(['jwks_url' => 'http://127.0.0.1:9999/jwks']));

        // Assert
        self::assertSame('http://127.0.0.1:9999/jwks', $settings->jwksUrl);
    }

    public function test_at_build_time_a_secret_is_a_reference_and_is_not_checked(): void
    {
        // Arrange: the build reads config without resolving secrets
        $configuration = new Configuration(['jwt' => ['issuer' => 'https://id.example.com/', 'audience' => 'api', 'algorithms' => ['HS256'], 'secret' => new EnvSecret('JWT_SECRET', '')]], [], false);

        // Act
        $settings = JwtSettings::fromConfiguration($configuration);

        // Assert
        self::assertNull($settings->secret);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function config(array $overrides): Configuration
    {
        return new Configuration(['jwt' => [...['issuer' => 'https://id.example.com/', 'audience' => 'https://api.example.com'], ...$overrides]]);
    }
}
