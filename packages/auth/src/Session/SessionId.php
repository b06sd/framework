<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

/**
 * Session ids: 32 random bytes as URL-safe base64 (43 characters). Only the SHA-256 of an id is ever
 * stored, so a leaked session table cannot be replayed as cookies.
 */
final class SessionId
{
    private const string PATTERN = '/^[A-Za-z0-9_-]{43}$/D';

    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function isValid(string $id): bool
    {
        return preg_match(self::PATTERN, $id) === 1;
    }

    public static function hash(string $id): string
    {
        return hash('sha256', $id);
    }

    public static function isValidHash(string $hash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }
}
