<?php

declare(strict_types=1);

namespace Trunk\Http\Uri;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Immutable RFC 3986 URI. Input containing whitespace, control characters or backslashes is rejected,
 * and a path can never be turned into an authority ("//host") by serialization.
 */
final class Uri implements UriInterface
{
    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    private const string UNRESERVED_SUBDELIMS = 'a-zA-Z0-9_\-\.~!\$&\'\(\)\*\+,;=';

    private string $scheme = '';

    private string $user = '';

    private ?string $password = null;

    private string $host = '';

    private ?int $port = null;

    private string $path = '';

    private string $query = '';

    private string $fragment = '';

    public function __construct(string $uri = '')
    {
        if ($uri === '') {
            return;
        }

        self::assertClean($uri, 'URI');

        if (preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?(?:#(.*))?$~D', $uri, $m, \PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new InvalidArgumentException('Unable to parse the URI.');
        }

        $this->scheme = self::filterScheme($m[1] ?? '');

        if ($m[2] !== null) {
            $this->applyAuthority($m[2]);
        }

        $this->path = self::filterPath($m[3]);
        $this->query = self::filterQuery($m[4] ?? '');
        $this->fragment = self::filterQuery($m[5] ?? '');
    }

    public function __toString(): string
    {
        $uri = '';
        $authority = $this->getAuthority();

        if ($this->scheme !== '') {
            $uri .= $this->scheme . ':';
        }

        if ($authority !== '') {
            $uri .= '//' . $authority;
        }

        $path = $this->path;

        if ($authority !== '' && $path !== '' && $path[0] !== '/') {
            $path = '/' . $path;
        } elseif ($authority === '' && str_starts_with($path, '//')) {
            $path = '/' . ltrim($path, '/');
        }

        $uri .= $path;

        if ($this->query !== '') {
            $uri .= '?' . $this->query;
        }

        if ($this->fragment !== '') {
            $uri .= '#' . $this->fragment;
        }

        return $uri;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        $authority = $this->getUserInfo();
        $authority = $authority === '' ? '' : $authority . '@';
        $authority .= $this->host;

        return $this->getPort() === null ? $authority : $authority . ':' . $this->getPort();
    }

    public function getUserInfo(): string
    {
        if ($this->user === '') {
            return '';
        }

        return $this->password === null ? $this->user : $this->user . ':' . $this->password;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port !== null && $this->port === (self::DEFAULT_PORTS[$this->scheme] ?? null) ? null : $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return $this->query;
    }

    public function getFragment(): string
    {
        return $this->fragment;
    }

    public function withScheme(string $scheme): static
    {
        $new = clone $this;
        $new->scheme = self::filterScheme($scheme);

        return $new;
    }

    public function withUserInfo(string $user, ?string $password = null): static
    {
        self::assertClean($user . ($password ?? ''), 'user info');
        $new = clone $this;
        $new->user = self::encode($user, self::UNRESERVED_SUBDELIMS);
        $new->password = $user === '' || $password === null ? null : self::encode($password, self::UNRESERVED_SUBDELIMS);

        return $new;
    }

    public function withHost(string $host): static
    {
        $new = clone $this;
        $new->host = self::filterHost($host);

        return $new;
    }

    public function withPort(?int $port): static
    {
        if ($port !== null && ($port < 0 || $port > 65535)) {
            throw new InvalidArgumentException('The port must be between 0 and 65535.');
        }

        $new = clone $this;
        $new->port = $port;

        return $new;
    }

    public function withPath(string $path): static
    {
        $new = clone $this;
        $new->path = self::filterPath($path);

        return $new;
    }

    public function withQuery(string $query): static
    {
        $new = clone $this;
        $new->query = self::filterQuery($query);

        return $new;
    }

    public function withFragment(string $fragment): static
    {
        $new = clone $this;
        $new->fragment = self::filterQuery($fragment);

        return $new;
    }

    private function applyAuthority(string $authority): void
    {
        $userInfo = '';

        if (($at = strrpos($authority, '@')) !== false) {
            $userInfo = substr($authority, 0, $at);
            $authority = substr($authority, $at + 1);
        }

        if (preg_match('/^(\[[^\]]*\]|[^:]*)(?::(\d*))?$/D', $authority, $m) !== 1) {
            throw new InvalidArgumentException('Invalid URI authority.');
        }

        [$user, $password] = array_pad(explode(':', $userInfo, 2), 2, null);
        $this->user = self::encode((string) $user, self::UNRESERVED_SUBDELIMS);
        $this->password = $password === null || $user === '' ? null : self::encode($password, self::UNRESERVED_SUBDELIMS);
        $this->host = self::filterHost($m[1]);

        if (($m[2] ?? '') !== '') {
            $port = (int) $m[2];

            if ($port > 65535) {
                throw new InvalidArgumentException('The port must be between 0 and 65535.');
            }

            $this->port = $port;
        }
    }

    private static function assertClean(string $value, string $what): void
    {
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $value) === 1) {
            throw new InvalidArgumentException(\sprintf('The %s contains forbidden characters.', $what));
        }
    }

    private static function filterScheme(string $scheme): string
    {
        if ($scheme !== '' && preg_match('/^[A-Za-z][A-Za-z0-9+.\-]*$/D', $scheme) !== 1) {
            throw new InvalidArgumentException('Invalid URI scheme.');
        }

        return strtolower($scheme);
    }

    private static function filterHost(string $host): string
    {
        $isIpLiteral = preg_match('/^\[[0-9A-Fa-f:.]+\]$/D', $host) === 1;

        if ($host !== '' && !$isIpLiteral && preg_match("/^[A-Za-z0-9\\-._~%!\$&'()*+,;=]+$/D", $host) !== 1) {
            throw new InvalidArgumentException('Invalid URI host.');
        }

        return strtolower($host);
    }

    private static function filterPath(string $path): string
    {
        self::assertClean($path, 'path');

        return self::encode($path, self::UNRESERVED_SUBDELIMS . ':@\/');
    }

    private static function filterQuery(string $value): string
    {
        self::assertClean($value, 'query or fragment');

        return self::encode($value, self::UNRESERVED_SUBDELIMS . ':@\/\?');
    }

    /**
     * Percent-encodes everything outside `$allowed` without double-encoding existing escapes.
     */
    private static function encode(string $value, string $allowed): string
    {
        return (string) preg_replace_callback(
            '/(?:[^' . $allowed . '%]++|%(?![A-Fa-f0-9]{2}))/',
            static fn(array $m): string => rawurlencode($m[0]),
            $value,
        );
    }
}
