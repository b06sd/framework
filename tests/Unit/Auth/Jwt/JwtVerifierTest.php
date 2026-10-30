<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Auth\Jwt;

use Firebase\JWT\JWT;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Trunk\Auth\Jwt\InvalidJwt;
use Trunk\Auth\Jwt\JwtSettings;
use Trunk\Auth\Jwt\JwtVerifier;
use Trunk\Auth\Jwt\KeySet;
use Trunk\Auth\Jwt\KeySource;
use Trunk\Foundation\Configuration;
use Trunk\Support\Directory;
use Trunk\Tests\Support\FixedClock;
use Trunk\Tests\Support\JwtKit;

final class JwtVerifierTest extends TestCase
{
    private FixedClock $clock;

    private string $cache;

    /** The provider's key set as it is published right now. */
    private string $published;

    private int $fetches = 0;

    private bool $providerDown = false;

    protected function setUp(): void
    {
        $this->clock = new FixedClock();
        $this->cache = sys_get_temp_dir() . '/trunk-jwks-' . bin2hex(random_bytes(4));
        $this->published = JwtKit::jwks(['k1' => JwtKit::shared()->rsa, 'e1' => JwtKit::shared()->ec], ['e1' => 'ES256']);
    }

    protected function tearDown(): void
    {
        new Directory()->remove($this->cache);
    }

    public function test_a_valid_token_gives_its_claims_with_scopes_from_scope_and_permissions(): void
    {
        // Arrange
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['sub' => 'user-7', 'scope' => 'read:orders write:orders', 'permissions' => ['admin', 'read:orders'], 'tenant' => 'acme']);

        // Act
        $claims = $this->verifier()->verify($token);

        // Assert
        self::assertSame('user-7', $claims->subject);
        self::assertSame([JwtKit::AUDIENCE], $claims->audience);
        self::assertSame(['read:orders', 'write:orders', 'admin'], $claims->scopes);
        self::assertTrue($claims->can('admin'));
        self::assertSame('acme', $claims->get('tenant'));
        self::assertSame(1_000_600, $claims->expiresAt);
    }

    public function test_an_es256_token_and_an_audience_list_are_accepted(): void
    {
        // Arrange
        $token = JwtKit::sign(JwtKit::shared()->ec, 'ES256', 'e1', ['aud' => ['https://other.example.com', JwtKit::AUDIENCE]]);

        // Act
        $claims = $this->verifier(['algorithms' => ['RS256', 'ES256']])->verify($token);

        // Assert
        self::assertSame(['https://other.example.com', JwtKit::AUDIENCE], $claims->audience);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedClaims(): iterable
    {
        yield 'expired (beyond the leeway)' => [['exp' => 1_000_000 - 31], 'expired'];
        yield 'not valid yet' => [['nbf' => 1_000_000 + 60], 'not valid yet'];
        yield 'issued in the future' => [['iat' => 1_000_000 + 60], 'not valid yet'];
        yield 'another issuer' => [['iss' => 'https://evil.example.com/'], 'wrong issuer'];
        yield 'an issuer that only starts the same' => [['iss' => JwtKit::ISSUER . 'x'], 'wrong issuer'];
        yield 'another audience' => [['aud' => 'https://other.example.com'], 'wrong audience'];
        yield 'no audience' => [['aud' => null], 'wrong audience'];
        yield 'no expiry' => [['exp' => null], 'no expiry'];
        yield 'no subject' => [['sub' => null], 'no subject'];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[DataProvider('refusedClaims')]
    public function test_tokens_with_wrong_claims_are_refused_with_the_reason(array $claims, string $reason): void
    {
        // Arrange
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', $claims);

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage($reason);
        $this->verifier()->verify($token);
    }

    public function test_the_leeway_absorbs_a_small_clock_difference(): void
    {
        // Arrange
        $token = JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', ['exp' => 1_000_000 - 20]);

        // Act
        $claims = $this->verifier()->verify($token);

        // Assert
        self::assertSame('1', $claims->subject);
    }

    public function test_a_changed_payload_fails_the_signature(): void
    {
        // Arrange
        [$header, , $signature] = explode('.', JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));
        $forged = JWT::urlsafeB64Encode(json_encode(['iss' => JwtKit::ISSUER, 'aud' => JwtKit::AUDIENCE, 'sub' => 'admin', 'exp' => 2_000_000], \JSON_THROW_ON_ERROR));

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('bad signature');
        $this->verifier()->verify($header . '.' . $forged . '.' . $signature);
    }

    public function test_a_token_signed_by_someone_elses_key_under_a_known_key_id_is_refused(): void
    {
        // Arrange
        $token = JwtKit::sign(JwtKit::shared()->otherRsa, 'RS256', 'k1');

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('bad signature');
        $this->verifier()->verify($token);
    }

    public function test_alg_none_is_never_accepted(): void
    {
        // Arrange
        $token = JWT::urlsafeB64Encode('{"alg":"none","typ":"JWT"}') . '.' . JWT::urlsafeB64Encode(json_encode(['iss' => JwtKit::ISSUER, 'aud' => JwtKit::AUDIENCE, 'sub' => '1', 'exp' => 2_000_000], \JSON_THROW_ON_ERROR)) . '.x';

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('algorithm "none" is not allowed');
        $this->verifier()->verify($token);
    }

    public function test_an_hs256_token_signed_with_the_public_key_is_refused(): void
    {
        // Arrange: the classic algorithm confusion, using the published public key as an HMAC secret
        $token = JwtKit::sign(JwtKit::publicPem(JwtKit::shared()->rsa), 'HS256', 'k1');

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('algorithm "HS256" is not allowed');
        $this->verifier()->verify($token);
    }

    public function test_a_key_the_key_set_labels_with_an_hs_algorithm_is_never_used(): void
    {
        // Arrange: even when HS256 is allowed (with a secret), a JWKS key is only ever an asymmetric key
        $this->published = JwtKit::jwks(['k1' => JwtKit::shared()->rsa, 'evil' => JwtKit::shared()->otherRsa], ['evil' => 'HS256']);
        $token = JwtKit::sign(JwtKit::publicPem(JwtKit::shared()->otherRsa), 'HS256', 'evil');

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('bad signature');
        $this->verifier(['algorithms' => ['RS256', 'HS256'], 'secret' => str_repeat('s', 32)])->verify($token);
    }

    public function test_an_hs256_token_is_checked_with_the_shared_secret(): void
    {
        // Arrange
        $secret = 'a-shared-secret-of-at-least-32-bytes!';

        // Act
        $claims = $this->verifier(['algorithms' => ['HS256'], 'secret' => $secret])->verify(JwtKit::sign($secret, 'HS256', null, ['sub' => 'u1']));

        // Assert
        self::assertSame('u1', $claims->subject);
    }

    public function test_an_algorithm_the_token_names_must_match_its_key(): void
    {
        // Arrange: an RSA key id, with the token claiming ES256
        $token = JwtKit::sign(JwtKit::shared()->ec, 'ES256', 'k1');

        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('unknown key id');
        $this->verifier(['algorithms' => ['RS256', 'ES256']])->verify($token);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformed(): iterable
    {
        yield 'empty' => [''];
        yield 'two parts' => ['a.b'];
        yield 'not base64url' => ['a.b.c+d'];
        yield 'header not json' => ['bm90IGpzb24.e30.c2ln'];
        yield 'far too long' => [str_repeat('a', 9000) . '.b.c'];
    }

    #[DataProvider('malformed')]
    public function test_malformed_tokens_are_refused(string $token): void
    {
        // Act & Assert
        $this->expectException(InvalidJwt::class);
        $this->expectExceptionMessage('malformed');
        $this->verifier()->verify($token);
    }

    public function test_a_rotated_key_is_fetched_when_a_token_first_uses_it(): void
    {
        // Arrange
        $verifier = $this->verifier();
        $verifier->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));
        $this->published = JwtKit::jwks(['k2' => JwtKit::shared()->otherRsa]);
        $this->clock->advance(61);

        // Act
        $claims = $verifier->verify(JwtKit::sign(JwtKit::shared()->otherRsa, 'RS256', 'k2', now: $this->clock->now));

        // Assert
        self::assertSame('1', $claims->subject);
        self::assertSame(2, $this->fetches);
    }

    public function test_made_up_key_ids_refetch_the_key_set_at_most_once_a_minute(): void
    {
        // Arrange
        $verifier = $this->verifier();
        $verifier->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));
        $this->clock->advance(61);

        // Act: a flood of tokens naming key ids that do not exist
        for ($i = 0; $i < 50; ++$i) {
            try {
                $verifier->verify(JwtKit::sign(JwtKit::shared()->otherRsa, 'RS256', 'random-' . $i, now: $this->clock->now));
            } catch (InvalidJwt) {
            }
        }

        // Assert
        self::assertSame(2, $this->fetches, 'one fetch at start, one refetch for the first unknown id, then none');
    }

    public function test_the_key_set_is_cached_on_disk_for_the_next_process(): void
    {
        // Arrange
        $this->verifier()->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));
        $this->providerDown = true;

        // Act: a new verifier, as in the next PHP process
        $claims = $this->verifier()->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));

        // Assert
        self::assertSame('1', $claims->subject);
        self::assertSame(1, $this->fetches);
        self::assertSame('0640', substr(\sprintf('%o', fileperms((glob($this->cache . '/jwks-*.json') ?: [''])[0])), -4));
    }

    public function test_when_the_provider_is_down_the_cached_keys_keep_working_after_they_expire(): void
    {
        // Arrange
        $verifier = $this->verifier();
        $verifier->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));
        $this->providerDown = true;
        $this->clock->advance(7200);

        // Act
        $claims = $verifier->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1', now: $this->clock->now));

        // Assert
        self::assertSame('1', $claims->subject);
    }

    public function test_with_no_keys_at_all_the_failure_is_an_error_not_a_401(): void
    {
        // Arrange
        $this->providerDown = true;

        // Act & Assert
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JWT signing keys are unavailable: provider down');
        $this->verifier()->verify(JwtKit::sign(JwtKit::shared()->rsa, 'RS256', 'k1'));
    }

    /**
     * @internal the fake provider
     */
    public function publish(string $url): string
    {
        if ($this->providerDown) {
            throw new RuntimeException('provider down');
        }

        self::assertSame('https://id.example.com/.well-known/jwks.json', $url);
        ++$this->fetches;

        return $this->published;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function verifier(array $overrides = []): JwtVerifier
    {
        $settings = JwtSettings::fromConfiguration(new Configuration(['jwt' => [...['issuer' => JwtKit::ISSUER, 'audience' => JwtKit::AUDIENCE, 'cache' => $this->cache], ...$overrides]]));
        $source = new class ($this) implements KeySource {
            public function __construct(private readonly JwtVerifierTest $test) {}

            public function fetch(string $url): string
            {
                return $this->test->publish($url);
            }
        };

        return new JwtVerifier($settings, new KeySet($settings, $source, $this->clock), $this->clock);
    }
}
