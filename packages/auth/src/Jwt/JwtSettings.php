<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use InvalidArgumentException;
use SensitiveParameter;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\EnvSecret;

/**
 * config/jwt.php, validated once. Every mistake is reported together, so `trunk build` lists them all.
 *
 * @internal
 */
final readonly class JwtSettings
{
    /** Signature algorithms checked with a public key from the provider's key set. */
    public const array ASYMMETRIC = ['RS256', 'RS384', 'RS512', 'PS256', 'ES256', 'ES384', 'EdDSA'];

    /** Signature algorithms checked with a shared secret (`JWT_SECRET`). */
    public const array SYMMETRIC = ['HS256', 'HS384', 'HS512'];

    public const array MATCHES = ['id', 'identifier', 'none'];

    /**
     * @param list<string> $algorithms
     * @param list<string> $scopeClaims
     */
    public function __construct(
        public string $issuer,
        public string $audience,
        public string $jwksUrl,
        public array $algorithms,
        #[SensitiveParameter]
        public ?string $secret,
        public int $leeway,
        public int $cacheTtl,
        public string $cachePath,
        public string $userClaim,
        public string $userMatch,
        public array $scopeClaims,
    ) {}

    /**
     * @throws InvalidArgumentException listing every problem
     */
    public static function fromConfiguration(Configuration $configuration): self
    {
        $errors = [];
        $string = static fn(string $key, string $default = ''): string => $configuration->has('jwt.' . $key) && \is_string($value = $configuration->get('jwt.' . $key)) ? trim($value) : $default;
        $int = static fn(string $key, int $default): int => $configuration->has('jwt.' . $key) && \is_int($value = $configuration->get('jwt.' . $key)) ? $value : $default;
        $list = static function (string $key, array $default) use ($configuration): array {
            $value = $configuration->has('jwt.' . $key) ? $configuration->get('jwt.' . $key) : $default;

            return \is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
        };

        $issuer = $string('issuer');
        $audience = $string('audience');
        $algorithms = $list('algorithms', ['RS256']);
        $rawSecret = $configuration->has('jwt.secret') ? $configuration->get('jwt.secret') : '';
        // During `trunk build` a secret is still a reference (its value never enters the build).
        $deferred = $rawSecret instanceof EnvSecret;
        $secret = \is_string($rawSecret) ? $rawSecret : '';
        $jwksUrl = $string('jwks_url');

        if ($issuer === '') {
            $errors[] = 'issuer is empty: set JWT_ISSUER to the provider\'s issuer, exactly as in its tokens\' "iss" (e.g. https://your-tenant.eu.auth0.com/).';
        }

        if ($audience === '') {
            $errors[] = 'audience is empty: set JWT_AUDIENCE to the identifier your API has at the provider (the tokens\' "aud").';
        }

        if ($algorithms === []) {
            $errors[] = 'algorithms is empty: list the ones your provider signs with, e.g. [\'RS256\'].';
        }

        foreach ($algorithms as $algorithm) {
            if (!\in_array($algorithm, [...self::ASYMMETRIC, ...self::SYMMETRIC], true)) {
                $errors[] = \sprintf('algorithms: "%s" is not supported (use %s). "none" is never accepted.', $algorithm, implode(', ', [...self::ASYMMETRIC, ...self::SYMMETRIC]));
            }
        }

        $symmetric = array_intersect($algorithms, self::SYMMETRIC) !== [];
        $asymmetric = array_intersect($algorithms, self::ASYMMETRIC) !== [];

        if ($symmetric && !$deferred && \strlen($secret) < 32) {
            $errors[] = 'algorithms lists an HS algorithm, which needs JWT_SECRET: the shared secret, at least 32 bytes.';
        }

        if (!$symmetric && $secret !== '' && !$deferred) {
            $errors[] = 'JWT_SECRET is set but algorithms lists no HS algorithm: remove the secret, or add the HS algorithm your provider uses.';
        }

        if ($asymmetric) {
            $jwksUrl = $jwksUrl !== '' ? $jwksUrl : rtrim($issuer, '/') . '/.well-known/jwks.json';

            if (!self::isAllowedUrl($jwksUrl)) {
                $errors[] = \sprintf('jwks_url "%s" must be an https URL (plain http only on 127.0.0.1, ::1 or localhost).', $jwksUrl);
            }
        }

        $match = $string('user.match', 'id');

        if (!\in_array($match, self::MATCHES, true)) {
            $errors[] = \sprintf('user.match "%s" must be one of %s.', $match, implode(', ', self::MATCHES));
        }

        $leeway = $int('leeway', 30);
        $cacheTtl = $int('cache_ttl', 3600);

        if ($leeway < 0 || $leeway > 300) {
            $errors[] = 'leeway is between 0 and 300 seconds.';
        }

        if ($cacheTtl < 60 || $cacheTtl > 86400) {
            $errors[] = 'cache_ttl is between 60 seconds and one day.';
        }

        if ($errors !== []) {
            throw new InvalidArgumentException(implode("\n", $errors));
        }

        return new self(
            $issuer,
            $audience,
            $asymmetric ? $jwksUrl : '',
            $algorithms,
            $symmetric && !$deferred ? $secret : null,
            $leeway,
            $cacheTtl,
            $string('cache'),
            $string('user.claim', 'sub'),
            $match,
            $list('scopes', ['scope', 'scp', 'permissions']),
        );
    }

    public static function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);

        return $scheme === 'https' || ($scheme === 'http' && \in_array(strtolower(trim($parts['host'], '[]')), ['127.0.0.1', '::1', 'localhost'], true));
    }
}
