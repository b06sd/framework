<?php

declare(strict_types=1);

namespace Trunk\Auth\Token;

use InvalidArgumentException;
use SensitiveParameter;
use Trunk\Auth\Settings\TokenSettings;
use Trunk\Auth\User\Authenticatable;
use Trunk\Contracts\Clock;

/**
 * Issues, verifies and revokes API tokens. A token looks like `trk_<id>.<secret>`: the id finds the
 * row, the 256-bit random secret proves possession, and only the secret's SHA-256 is stored, so a
 * leaked table cannot be used. A token also remembers its owner's session version, so changing that
 * version (a password change) ends every token as well as every session. Verification compares in
 * constant time and gives one answer (null) for every kind of failure.
 *
 * @api
 */
final readonly class TokenManager
{
    private const string FORMAT = '/^trk_([A-Za-z0-9_-]{16})\.([A-Za-z0-9_-]{43})$/D';

    /** @internal wired by the container */
    public function __construct(private TokenStore $store, private TokenSettings $settings, private Clock $clock) {}

    /**
     * @param list<string> $abilities what the token may do (`['*']` for everything)
     * @param int|null     $ttl       seconds until it expires; null uses `auth.tokens.ttl`, 0 never expires
     */
    public function issue(Authenticatable $user, string $name, array $abilities = ['*'], ?int $ttl = null): NewToken
    {
        $this->assertName($name);
        $this->assertAbilities($abilities);
        $ttl ??= $this->settings->ttl;

        if ($ttl < 0 || $ttl > 315_360_000) {
            throw new InvalidArgumentException('A token lifetime is 0 (never) or up to ten years, in seconds.');
        }

        $now = $this->clock->now();
        $id = self::random(12);
        $secret = self::random(32);
        $token = new AccessToken($id, $user->authId(), $user->authSessionVersion(), $name, array_values(array_unique($abilities)), $ttl === 0 ? null : $now + $ttl, null, null, $now);
        $this->store->create(new TokenRecord($token, hash('sha256', $secret)));

        return new NewToken('trk_' . $id . '.' . $secret, $token);
    }

    /**
     * The token behind a bearer value, or null when it is malformed, unknown, wrong, expired or
     * revoked. A successful check notes the use (at most once per `auth.tokens.touch_interval`).
     */
    public function verify(#[SensitiveParameter] string $bearer): ?AccessToken
    {
        if (preg_match(self::FORMAT, $bearer, $m) !== 1) {
            return null;
        }

        $record = $this->store->find($m[1]);
        $expected = $record->secretHash ?? hash('sha256', 'no such token');

        if (!hash_equals($expected, hash('sha256', $m[2])) || $record === null) {
            return null;
        }

        $now = $this->clock->now();

        if (!$record->token->isActive($now)) {
            return null;
        }

        if ($record->token->lastUsedAt === null || $now - $record->token->lastUsedAt >= $this->settings->touchInterval) {
            $this->store->touch($record->token->id, $now);
        }

        return $record->token;
    }

    public function revoke(string $tokenId): void
    {
        $this->store->revoke($tokenId, $this->clock->now());
    }

    public function revokeAllFor(Authenticatable $user): int
    {
        return $this->store->revokeAllFor($user->authId(), $this->clock->now());
    }

    /**
     * @return list<AccessToken>
     */
    public function forUser(Authenticatable $user): array
    {
        return $this->store->forUser($user->authId());
    }

    private function assertName(string $name): void
    {
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new InvalidArgumentException('A token name is 1 to 100 characters without control characters.');
        }
    }

    /**
     * @param list<string> $abilities
     */
    private function assertAbilities(array $abilities): void
    {
        if ($abilities === [] || \count($abilities) > 50) {
            throw new InvalidArgumentException('A token needs between 1 and 50 abilities.');
        }

        foreach ($abilities as $ability) {
            if ($ability !== '*' && preg_match('/^[a-z][a-z0-9:_.-]{0,63}$/D', $ability) !== 1) {
                throw new InvalidArgumentException('An ability is "*" or lowercase letters, digits and : _ . - (max 64), starting with a letter.');
            }
        }
    }

    /**
     * @param positive-int $bytes
     */
    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
