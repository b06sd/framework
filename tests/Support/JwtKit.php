<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Firebase\JWT\JWT;
use LogicException;
use OpenSSLAsymmetricKey;

/**
 * Real key pairs and signed tokens for JWT tests: an RSA and an EC (P-256) key, their public halves as
 * a JWKS, and `sign()` to issue a token the way an identity provider would.
 */
final class JwtKit
{
    public const string ISSUER = 'https://id.example.com/';

    public const string AUDIENCE = 'https://api.example.com';

    private static ?self $shared = null;

    private function __construct(
        public readonly OpenSSLAsymmetricKey $rsa,
        public readonly OpenSSLAsymmetricKey $ec,
        public readonly OpenSSLAsymmetricKey $otherRsa,
    ) {}

    public static function shared(): self
    {
        return self::$shared ??= new self(self::key(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]), self::key(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']), self::key(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]));
    }

    /**
     * The JWKS a provider would publish, with the keys named.
     *
     * @param array<string, OpenSSLAsymmetricKey> $keys kid => private key (only its public half is published)
     * @param array<string, string>               $alg  kid => "alg" to publish (none by default)
     */
    public static function jwks(array $keys, array $alg = []): string
    {
        $published = [];

        foreach ($keys as $kid => $key) {
            $details = openssl_pkey_get_details($key) ?: throw new LogicException('no key details');
            $jwk = isset($details['rsa'])
                ? ['kty' => 'RSA', 'n' => self::field($details, 'rsa', 'n'), 'e' => self::field($details, 'rsa', 'e')]
                : ['kty' => 'EC', 'crv' => 'P-256', 'x' => self::field($details, 'ec', 'x'), 'y' => self::field($details, 'ec', 'y')];
            $published[] = ['kid' => $kid, 'use' => 'sig', ...$jwk, ...(isset($alg[$kid]) ? ['alg' => $alg[$kid]] : [])];
        }

        return json_encode(['keys' => $published], \JSON_THROW_ON_ERROR);
    }

    /**
     * A token with sensible claims, overridden by `$claims` (a null value removes the claim).
     *
     * @param array<string, mixed> $claims
     */
    public static function sign(OpenSSLAsymmetricKey|string $key, string $alg, ?string $kid, array $claims = [], int $now = 1_000_000): string
    {
        $payload = array_filter([...['iss' => self::ISSUER, 'aud' => self::AUDIENCE, 'sub' => '1', 'iat' => $now, 'exp' => $now + 600], ...$claims], static fn(mixed $v): bool => $v !== null);

        return JWT::encode($payload, $key, $alg, $kid);
    }

    public static function publicPem(OpenSSLAsymmetricKey $key): string
    {
        $pem = (openssl_pkey_get_details($key) ?: throw new LogicException('no key details'))['key'] ?? null;

        return \is_string($pem) ? $pem : throw new LogicException('no public key');
    }

    /**
     * @param array<array-key, mixed> $details
     */
    private static function field(array $details, string $type, string $name): string
    {
        $part = $details[$type] ?? null;
        $bytes = \is_array($part) ? ($part[$name] ?? null) : null;

        return \is_string($bytes) ? self::b64($bytes) : throw new LogicException('no ' . $type . '.' . $name);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function key(array $options): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new($options) ?: throw new LogicException('openssl could not make a key');
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
