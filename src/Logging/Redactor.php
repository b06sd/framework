<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * Replaces secrets with [REDACTED] before anything is written. Keys are matched case-insensitively
 * and ignoring `_`, `-` and `.`, by substring, so `user_password_hash`, `X-Api-Key` and `Set-Cookie`
 * are all caught. The default list can be extended by the application, never shortened.
 */
final readonly class Redactor
{
    public const string MASK = '[REDACTED]';

    private const array DEFAULT_KEYS = ['password', 'passwd', 'passphrase', 'secret', 'token', 'authorization', 'cookie', 'apikey', 'privatekey', 'creditcard', 'cardnumber', 'cvv', 'cvc', 'sessionid'];

    /** @var list<string> */
    private array $keys;

    /**
     * @param list<string> $extraKeys
     */
    public function __construct(array $extraKeys = [])
    {
        $keys = self::DEFAULT_KEYS;

        foreach ($extraKeys as $key) {
            $normalized = self::normalize($key);

            if ($normalized !== '') {
                $keys[] = $normalized;
            }
        }

        $this->keys = array_values(array_unique($keys));
    }

    public function sensitive(string $key): bool
    {
        $normalized = self::normalize($key);

        foreach ($this->keys as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scrubs secrets that appear inside free text (a message, an exception message, a URL).
     */
    public function scrub(string $text): string
    {
        $text = preg_replace('/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}?(?=Bearer|Basic|[^A-Za-z0-9._~+\/=-]|$)/i', '$1 ' . self::MASK, $text) ?? $text;
        $text = preg_replace('/(:\/\/)[^\s\/:@]+:[^\s\/@]+@/', '$1' . self::MASK . '@', $text) ?? $text;

        return preg_replace('/(password|passwd|secret|token|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret)(\s*[=:]\s*)[^\s&,;"\']+/i', '$1$2' . self::MASK, $text) ?? $text;
    }

    private static function normalize(string $key): string
    {
        return strtolower(str_replace(['_', '-', '.', ' '], '', $key));
    }
}
