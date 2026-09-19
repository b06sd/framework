<?php

declare(strict_types=1);

namespace Trunk\Auth\Cookie;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * One `Set-Cookie` header value, validated so a name, value or attribute can never break out of it
 * (no `;`, `,`, spaces, control characters or CR/LF). `__Host-` cookies are held to their rules:
 * Secure, `Path=/`, no Domain.
 *
 * @api
 */
final readonly class Cookie
{
    public function __construct(
        public string $name,
        #[SensitiveParameter]
        public string $value,
        public ?int $maxAge = null,
        public string $path = '/',
        public ?string $domain = null,
        public bool $secure = true,
        public bool $httpOnly = true,
        public string $sameSite = 'Lax',
    ) {
        if (preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]{1,64}$/D', $name) !== 1) {
            throw new InvalidArgumentException('A cookie name uses only letters, digits and !#$%&\'*+.^_`|~- (at most 64).');
        }

        if (preg_match('/^[\x21\x23-\x2B\x2D-\x3A\x3C-\x5B\x5D-\x7E]{0,4096}$/D', $value) !== 1) {
            throw new InvalidArgumentException('A cookie value may not contain spaces, quotes, commas, semicolons, backslashes or control characters (at most 4096).');
        }

        if (preg_match('#^/[\x21\x23-\x3A\x3C-\x7E]*$#D', $path) !== 1) {
            throw new InvalidArgumentException('A cookie path starts with "/" and contains no spaces, semicolons or control characters.');
        }

        if ($domain !== null && preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $domain) !== 1) {
            throw new InvalidArgumentException('A cookie domain contains only letters, digits, dots and hyphens.');
        }

        if (!\in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new InvalidArgumentException('SameSite must be Lax, Strict or None.');
        }

        if ($sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('SameSite=None requires the Secure attribute.');
        }

        if ($maxAge !== null && ($maxAge < 0 || $maxAge > 31_536_000 * 10)) {
            throw new InvalidArgumentException('Max-Age must be between 0 and ten years.');
        }

        if (str_starts_with($name, '__Host-') && (!$secure || $path !== '/' || $domain !== null)) {
            throw new InvalidArgumentException('A __Host- cookie must be Secure, have Path=/ and no Domain.');
        }

        if (str_starts_with($name, '__Secure-') && !$secure) {
            throw new InvalidArgumentException('A __Secure- cookie must be Secure.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'value' => '[REDACTED]', 'maxAge' => $this->maxAge, 'path' => $this->path, 'secure' => $this->secure, 'httpOnly' => $this->httpOnly, 'sameSite' => $this->sameSite];
    }

    /**
     * A cookie that makes the browser delete the named one.
     */
    public static function forget(string $name, bool $secure = true, string $path = '/', ?string $domain = null, string $sameSite = 'Lax'): self
    {
        return new self($name, '', 0, $path, $domain, $secure, true, $sameSite);
    }

    public function header(): string
    {
        $parts = [$this->name . '=' . $this->value];

        if ($this->maxAge !== null) {
            $parts[] = 'Max-Age=' . $this->maxAge;

            if ($this->maxAge === 0) {
                $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
            }
        }

        $parts[] = 'Path=' . $this->path;

        if ($this->domain !== null) {
            $parts[] = 'Domain=' . $this->domain;
        }

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        if ($this->httpOnly) {
            $parts[] = 'HttpOnly';
        }

        $parts[] = 'SameSite=' . $this->sameSite;

        return implode('; ', $parts);
    }
}
