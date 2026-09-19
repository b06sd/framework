<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

use InvalidArgumentException;
use JsonException;

/**
 * Session data as JSON, never PHP serialization: decoding a stored session can only ever produce
 * scalars and arrays, so a tampered store cannot instantiate objects. Oversized data is refused.
 */
final class SessionCodec
{
    public const int MAX_BYTES = 65_536;

    public static function encode(SessionRecord $record): string
    {
        try {
            $json = json_encode(['d' => $record->data, 'c' => $record->createdAt, 'a' => $record->lastActivity], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES, 32);
        } catch (JsonException) {
            throw new InvalidArgumentException('Session data must contain only strings, numbers, booleans, null and arrays of them.');
        }

        if (\strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException(\sprintf('Session data is larger than %d bytes; keep the session small and store the rest in the database.', self::MAX_BYTES));
        }

        return $json;
    }

    /**
     * Null for anything that is not a well-formed record; a damaged session is simply no session.
     */
    public static function decode(string $json): ?SessionRecord
    {
        if ($json === '' || \strlen($json) > self::MAX_BYTES) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_array($decoded['d'] ?? null) || !\is_int($decoded['c'] ?? null) || !\is_int($decoded['a'] ?? null)) {
            return null;
        }

        $data = [];

        foreach ($decoded['d'] as $key => $value) {
            $data[(string) $key] = $value;
        }

        return new SessionRecord($data, $decoded['c'], $decoded['a']);
    }
}
