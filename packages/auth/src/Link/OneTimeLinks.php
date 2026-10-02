<?php

declare(strict_types=1);

namespace Trunk\Auth\Link;

use SensitiveParameter;
use Trunk\Auth\Settings\LinkSettings;
use Trunk\Auth\User\Authenticatable;
use Trunk\Auth\User\UserProvider;
use Trunk\Contracts\Clock;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Query\QueryBuilder;

/**
 * One-time links (password reset, email verification). A link token looks like `<id>.<secret>`: the id
 * finds the row, the 256-bit random secret proves possession, and only the secret's SHA-256 is stored,
 * so a leaked table cannot be used. A link is bound to its purpose and to the user's session version
 * (a password change kills it), expires, and works once: using it deletes the row in one conditional
 * DELETE, so two simultaneous uses cannot both succeed. Every kind of failure is the same null.
 *
 * @internal used by PasswordReset and EmailVerification
 */
final readonly class OneTimeLinks
{
    private const string FORMAT = '/^([A-Za-z0-9_-]{16})\.([A-Za-z0-9_-]{43})$/D';

    public function __construct(private Connection $connection, private LinkSettings $settings, private UserProvider $users, private Clock $clock) {}

    /**
     * A new link token, replacing any earlier link for the same user and purpose; null when one was
     * issued less than `resend_after` seconds ago (so a form cannot flood an inbox).
     */
    public function issue(string $purpose, Authenticatable $user, int $ttl, ?string $address = null): ?string
    {
        $now = $this->clock->now();
        $mine = fn(): QueryBuilder => $this->table()->where('user_id', '=', $user->authId())->where('purpose', '=', $purpose);

        if ($this->settings->resendAfter > 0 && $mine()->where('created_at', '>', $now - $this->settings->resendAfter)->exists()) {
            return null;
        }

        $mine()->delete();
        $id = self::random(12);
        $secret = self::random(32);
        $this->table()->insert([
            'id' => $id,
            'token_hash' => hash('sha256', $secret),
            'purpose' => $purpose,
            'user_id' => $user->authId(),
            'user_version' => $user->authSessionVersion(),
            'address' => $address,
            'expires_at' => $now + $ttl,
            'created_at' => $now,
        ]);

        return $id . '.' . $secret;
    }

    /**
     * The user (and address) a valid link belongs to, without using it up: for showing a form.
     *
     * @return array{Authenticatable, ?string}|null
     */
    public function find(string $purpose, #[SensitiveParameter] string $token): ?array
    {
        return $this->check($purpose, $token)[0] ?? null;
    }

    /**
     * Uses the link up and returns its user (and address); null if it is not valid, or another
     * request used it first.
     *
     * @return array{Authenticatable, ?string}|null
     */
    public function consume(string $purpose, #[SensitiveParameter] string $token): ?array
    {
        $checked = $this->check($purpose, $token);

        if ($checked === null) {
            return null;
        }

        [$found, $id, $hash] = $checked;

        return $this->table()->where('id', '=', $id)->where('token_hash', '=', $hash)->delete() === 1 ? $found : null;
    }

    public function prune(int $now): int
    {
        return $this->table()->where('expires_at', '<=', $now)->delete();
    }

    /**
     * @return array{array{Authenticatable, ?string}, string, string}|null
     */
    private function check(string $purpose, #[SensitiveParameter] string $token): ?array
    {
        if (preg_match(self::FORMAT, $token, $m) !== 1) {
            return null;
        }

        $row = $this->table()->where('id', '=', $m[1])->where('purpose', '=', $purpose)->first();
        $stored = \is_string($row['token_hash'] ?? null) ? $row['token_hash'] : hash('sha256', 'no such link');
        $hash = hash('sha256', $m[2]);

        if (!hash_equals($stored, $hash) || $row === null || !is_numeric($row['expires_at'] ?? null) || (int) $row['expires_at'] <= $this->clock->now()) {
            return null;
        }

        $user = \is_scalar($row['user_id'] ?? null) ? $this->users->byId((string) $row['user_id']) : null;
        $version = $row['user_version'] ?? null;

        if ($user === null || !\is_scalar($version) || !hash_equals($user->authSessionVersion(), (string) $version)) {
            return null;
        }

        $address = $row['address'] ?? null;

        return [[$user, \is_string($address) ? $address : null], $m[1], $hash];
    }

    private function table(): QueryBuilder
    {
        return $this->connection->table($this->settings->table);
    }

    /**
     * @param positive-int $bytes
     */
    private static function random(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
