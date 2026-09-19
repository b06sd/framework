<?php

declare(strict_types=1);

namespace Trunk\Auth\Csrf;

use SensitiveParameter;
use Trunk\Auth\Session\Session;

/**
 * The CSRF secret lives in the session; what is embedded in pages is a fresh one-time-pad mask of it
 * on every call (the same secret never appears twice, so compression side channels such as BREACH
 * learn nothing). Verification unmasks and compares in constant time.
 */
final class CsrfTokens
{
    private const int SECRET_BYTES = 32;

    public function token(Session $session): string
    {
        $secret = $this->secret($session);
        $pad = random_bytes(self::SECRET_BYTES);

        return self::encode($pad . ($secret ^ $pad));
    }

    public function verify(Session $session, #[SensitiveParameter] string $submitted): bool
    {
        $stored = $session->internal('csrf');

        if (!\is_string($stored) || $submitted === '' || \strlen($submitted) !== 86) {
            return false;
        }

        $secret = self::decode($stored);
        $raw = self::decode($submitted);

        if ($secret === null || $raw === null || \strlen($raw) !== 2 * self::SECRET_BYTES || \strlen($secret) !== self::SECRET_BYTES) {
            return false;
        }

        $pad = substr($raw, 0, self::SECRET_BYTES);

        return hash_equals($secret, substr($raw, self::SECRET_BYTES) ^ $pad);
    }

    private function secret(Session $session): string
    {
        $stored = $session->internal('csrf');
        $secret = \is_string($stored) ? self::decode($stored) : null;

        if ($secret === null || \strlen($secret) !== self::SECRET_BYTES) {
            $secret = random_bytes(self::SECRET_BYTES);
            $session->setInternal('csrf', self::encode($secret));
        }

        return $secret;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            return null;
        }

        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
