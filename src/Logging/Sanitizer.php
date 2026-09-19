<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * Makes untrusted text safe to write to a log line: control characters, line breaks, terminal
 * escape sequences and bidirectional overrides are escaped (a forged "\n2026-... ERROR ..." line
 * or an ANSI sequence cannot pass through), invalid UTF-8 is replaced, and length is capped.
 */
final class Sanitizer
{
    public const int MAX_LENGTH = 8192;

    public static function text(string $value, int $max = self::MAX_LENGTH): string
    {
        $truncated = false;

        if (\strlen($value) > $max) {
            $value = preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', substr($value, 0, $max)) ?? '';
            $truncated = true;
        }

        if (preg_match('//u', $value) !== 1) {
            $value = preg_replace('/[^\x20-\x7E]/', '?', $value) ?? '';
        }

        $clean = preg_replace_callback('/[\x00-\x1F\x7F]|[\x{0085}\x{2028}\x{2029}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200B}-\x{200F}\x{FEFF}]/u', static fn(array $m): string => self::escape($m[0]), $value) ?? '';

        return $truncated ? $clean . '...[truncated]' : $clean;
    }

    private static function escape(string $character): string
    {
        return match ($character) {
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
            default => \strlen($character) === 1 ? \sprintf('\\x%02x', \ord($character)) : \sprintf('\\u%04x', mb_ord($character, 'UTF-8')),
        };
    }
}
