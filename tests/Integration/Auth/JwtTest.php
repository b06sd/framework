<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Auth\Jwt\JwtModule;
use Trunk\Tests\Fixtures\Auth\JwtTestModule;
use Trunk\Tests\Support\AuthApp;
use Trunk\Tests\Support\JwtKit;

/**
 * RequireJwt in a complete application (real kernel, container and database), development and compiled.
 */
final class JwtTest extends TestCase
{
    /** @var list<AuthApp> */
    private array $apps = [];

    private string $jwks;

    protected function setUp(): void
    {
        $this->jwks = sys_get_temp_dir() . '/trunk-jwks-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->jwks, JwtKit::jwks(['k1' => JwtKit::shared()->rsa]));
    }

    protected function tearDown(): void
    {
        foreach ($this->apps as $app) {
            $app->cleanUp();
        }

        @unlink($this->jwks);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development' => ['development'];
        yield 'compiled' => ['compiled'];
    }

    #[DataProvider('modes')]
    public function test_a_valid_token_signs_in_its_local_user_and_its_scopes_are_its_abilities(string $mode): void
    {
        // Arrange
        $app = $this->app();
        $id = $app->createUser('ada@example.com');
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => $id, 'scope' => 'read'], time());

        // Act
        $response = $app->client($mode)->get('/jwt/whoami', ['Authorization' => 'Bearer ' . $token]);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id' => $id, 'subject' => $id, 'scopes' => ['read'], 'canRead' => true, 'canWrite' => false], json_decode((string) $response->getBody(), true));
    }

    #[DataProvider('modes')]
    public function test_every_refusal_is_the_same_401(string $mode): void
    {
        // Arrange
        $app = $this->app();
        $id = $app->createUser('ada@example.com');
        $client = $app->client($mode);
        $tokens = [
            'none' => null,
            'garbage' => 'Bearer not-a-jwt',
            'expired' => 'Bearer ' . JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => $id, 'exp' => time() - 3600], time() - 7200),
            'wrong audience' => 'Bearer ' . JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => $id, 'aud' => 'someone-else'], time()),
            'no such user' => 'Bearer ' . JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => '999'], time()),
            'an API token' => 'Bearer ' . $app->tokens()->issue($app->user($id), 'ci')->plainText,
        ];

        foreach ($tokens as $case => $authorization) {
            // Act
            $response = $client->get('/jwt/whoami', $authorization === null ? [] : ['Authorization' => $authorization]);

            // Assert
            self::assertSame(401, $response->getStatusCode(), $case);
            self::assertSame($authorization === null ? 'Bearer' : 'Bearer error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'), $case);
            self::assertStringNotContainsString('audience', (string) $response->getBody(), $case . ': the reason is logged, never sent');
        }
    }

    public function test_the_reason_for_a_refusal_is_logged_without_the_token(): void
    {
        // Arrange
        $logs = sys_get_temp_dir() . '/trunk-jwt-logs-' . bin2hex(random_bytes(4));
        $app = $this->apps[] = new AuthApp(extraModules: [JwtModule::class, JwtTestModule::class], logging: ['channel' => 'file', 'level' => 'info', 'path' => $logs], configuration: [
            'jwt' => ['issuer' => JwtKit::ISSUER, 'audience' => JwtKit::AUDIENCE, 'cache' => ''],
            'jwt_test' => ['jwks' => $this->jwks],
        ]);
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['aud' => 'someone-else'], time());

        // Act
        $app->client('development')->get('/jwt/whoami', ['Authorization' => 'Bearer ' . $token]);
        $written = implode('', array_map(static fn(string $f): string => (string) file_get_contents($f), glob($logs . '/*') ?: []));
        array_map(\unlink(...), glob($logs . '/*') ?: []);
        @rmdir($logs);

        // Assert
        self::assertStringContainsString('JWT refused: wrong audience', $written);
        self::assertStringNotContainsString(explode('.', $token)[2], $written);
    }

    #[DataProvider('modes')]
    public function test_a_jwt_does_not_open_a_route_that_takes_api_tokens(string $mode): void
    {
        // Arrange
        $app = $this->app();
        $id = $app->createUser('ada@example.com');
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => $id], time());

        // Act
        $response = $app->client($mode)->get('/token/whoami', ['Authorization' => 'Bearer ' . $token]);

        // Assert
        self::assertSame(401, $response->getStatusCode());
    }

    public function test_with_no_local_users_the_route_sees_only_the_claims(): void
    {
        // Arrange
        $app = $this->app(['user' => ['match' => 'none']]);
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => 'machine@clients', 'permissions' => ['write']], time());

        // Act
        $response = $app->client('compiled')->get('/jwt/whoami', ['Authorization' => 'Bearer ' . $token]);

        // Assert
        self::assertSame(['id' => null, 'subject' => 'machine@clients', 'scopes' => ['write'], 'canRead' => false, 'canWrite' => true], json_decode((string) $response->getBody(), true));
    }

    public function test_an_email_claim_finds_a_user_only_when_the_provider_verified_it(): void
    {
        // Arrange
        $app = $this->app(['user' => ['claim' => 'email', 'match' => 'identifier']]);
        $id = $app->createUser('ada@example.com');
        $client = $app->client('development');

        // Act
        $verified = $client->get('/jwt/whoami', self::emailToken(['email_verified' => true]));
        $unverified = $client->get('/jwt/whoami', self::emailToken(['email_verified' => false]));
        $unsaid = $client->get('/jwt/whoami', self::emailToken([]));

        // Assert
        self::assertSame($id, $client->json($verified)['id'] ?? null);
        self::assertSame([401, 401], [$unverified->getStatusCode(), $unsaid->getStatusCode()]);
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return array<string, string>
     */
    private static function emailToken(array $claims): array
    {
        return ['Authorization' => 'Bearer ' . JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => 'auth0|1', 'email' => 'ada@example.com', ...$claims], time())];
    }

    /**
     * @param array<string, mixed> $jwt
     */
    private function app(array $jwt = []): AuthApp
    {
        return $this->apps[] = new AuthApp(extraModules: [JwtModule::class, JwtTestModule::class], configuration: [
            'jwt' => [...['issuer' => JwtKit::ISSUER, 'audience' => JwtKit::AUDIENCE, 'algorithms' => ['RS256'], 'cache' => ''], ...$jwt],
            'jwt_test' => ['jwks' => $this->jwks],
        ]);
    }
}
