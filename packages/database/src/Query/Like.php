<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

/**
 * Escapes user text for use inside a LIKE pattern. The builder always adds `ESCAPE '!'` to LIKE
 * comparisons, so `!`, `%` and `_` in the text lose their special meaning.
 *
 * @api
 */
final class Like
{
    public static function escape(string $text): string
    {
        return strtr($text, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    public static function contains(string $text): string
    {
        return '%' . self::escape($text) . '%';
    }

    public static function startsWith(string $text): string
    {
        return self::escape($text) . '%';
    }

    public static function endsWith(string $text): string
    {
        return '%' . self::escape($text);
    }
}
