<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use JsonException;
use RuntimeException;
use SensitiveParameter;
use Throwable;
use Trunk\Contracts\Clock;

/**
 * Verifies a JWT from your identity provider: the signature (with a key from the provider's key set,
 * or the shared secret for HS algorithms), only the configured algorithms, the issuer, the audience,
 * an expiry that must be present, and "not before". Signatures are checked by firebase/php-jwt.
 * Use it where `RequireJwt` does not reach, such as a WebSocket handshake.
 *
 * @api
 */
final readonly class JwtVerifier
{
    private const int MAX_LENGTH = 8192;

    /** @internal wired by the container */
    public function __construct(private JwtSettings $settings, private KeySet $keys, private Clock $clock) {}

    /**
     * @throws InvalidJwt        with the reason, when the token is refused
     * @throws RuntimeException when the provider's keys cannot be fetched (and none are cached)
     */
    public function verify(#[SensitiveParameter] string $token): Claims
    {
        if (\strlen($token) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) !== 1) {
            throw new InvalidJwt('malformed');
        }

        $header = self::part(explode('.', $token)[0]);
        $algorithm = \is_string($header['alg'] ?? null) ? $header['alg'] : '';

        if (!\in_array($algorithm, $this->settings->algorithms, true)) {
            throw new InvalidJwt(\sprintf('algorithm "%s" is not allowed', substr($algorithm, 0, 16)));
        }

        $payload = $this->decode($token, $this->key($algorithm, $header));

        return $this->claims($payload);
    }

    /**
     * @param array<string, mixed> $header
     */
    private function key(string $algorithm, array $header): Key
    {
        if (\in_array($algorithm, JwtSettings::SYMMETRIC, true)) {
            return $this->settings->secret !== null ? new Key($this->settings->secret, $algorithm) : throw new InvalidJwt('no secret for HS tokens');
        }

        $kid = $header['kid'] ?? null;

        if (!\is_string($kid) || $kid === '' || \strlen($kid) > 256) {
            throw new InvalidJwt('no key id');
        }

        $key = $this->keys->key($kid);

        return $key !== null && $key->getAlgorithm() === $algorithm ? $key : throw new InvalidJwt('unknown key id');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(#[SensitiveParameter] string $token, Key $key): array
    {
        // firebase/php-jwt reads the time and the leeway from two static properties; they are set from
        // the injected clock for this one call and put back afterwards.
        [$timestamp, $leeway] = [JWT::$timestamp, JWT::$leeway];
        JWT::$timestamp = $this->clock->now();
        JWT::$leeway = $this->settings->leeway;

        try {
            $payload = JWT::decode($token, $key);
        } catch (ExpiredException) {
            throw new InvalidJwt('expired');
        } catch (BeforeValidException) {
            throw new InvalidJwt('not valid yet');
        } catch (SignatureInvalidException) {
            throw new InvalidJwt('bad signature');
        } catch (Throwable $e) {
            throw new InvalidJwt('refused: ' . substr($e->getMessage(), 0, 80));
        } finally {
            [JWT::$timestamp, JWT::$leeway] = [$timestamp, $leeway];
        }

        try {
            $claims = json_decode(json_encode($payload, \JSON_THROW_ON_ERROR), true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidJwt('malformed claims');
        }

        if (!\is_array($claims)) {
            throw new InvalidJwt('malformed claims');
        }

        /** @var array<string, mixed> $claims */
        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function claims(array $claims): Claims
    {
        if (!\is_int($claims['exp'] ?? null) && !\is_float($claims['exp'] ?? null)) {
            throw new InvalidJwt('no expiry');
        }

        if (($claims['iss'] ?? null) !== $this->settings->issuer) {
            throw new InvalidJwt('wrong issuer');
        }

        $audience = \is_string($claims['aud'] ?? null) ? [$claims['aud']] : (\is_array($claims['aud'] ?? null) ? array_values(array_filter($claims['aud'], is_string(...))) : []);

        if (!\in_array($this->settings->audience, $audience, true)) {
            throw new InvalidJwt('wrong audience');
        }

        $subject = $claims['sub'] ?? null;

        if (!\is_string($subject) || $subject === '') {
            throw new InvalidJwt('no subject');
        }

        $scopes = [];

        foreach ($this->settings->scopeClaims as $name) {
            $value = $claims[$name] ?? null;
            $scopes = [...$scopes, ...match (true) {
                \is_string($value) => preg_split('/\s+/', trim($value), -1, \PREG_SPLIT_NO_EMPTY) ?: [],
                \is_array($value) => array_values(array_filter($value, is_string(...))),
                default => [],
            }];
        }

        return new Claims($subject, $this->settings->issuer, $audience, (int) $claims['exp'], array_values(array_unique($scopes)), $claims);
    }

    /**
     * @return array<string, mixed>
     */
    private static function part(string $segment): array
    {
        try {
            $decoded = json_decode(JWT::urlsafeB64Decode($segment), true, 8, \JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new InvalidJwt('malformed');
        }

        if (!\is_array($decoded)) {
            throw new InvalidJwt('malformed');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
