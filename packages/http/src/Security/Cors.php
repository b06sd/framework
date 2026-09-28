<?php

declare(strict_types=1);

namespace Trunk\Http\Security;

use InvalidArgumentException;
use Trunk\Foundation\Configuration;

/**
 * Cross-origin resource sharing, as one policy: which origins may call this API from a browser, with
 * which methods and headers, and whether the browser may send cookies. Off by default (`enabled` is
 * false in config/http.php), because most APIs are not meant to be called from arbitrary web pages;
 * turn it on and list the origins you actually want to allow.
 *
 * Values are validated once, at construction (and so at build time, by `HttpModule::plan()`):
 * `*` cannot be combined with `allow_credentials` (browsers refuse that combination anyway, so this
 * catches the mistake before it reaches one), every origin is a plain `scheme://host[:port]` with no
 * path, and every method and header is a plain token.
 *
 * @api
 */
final readonly class Cors
{
    private const int MAX_AGE_CEILING = 31_536_000;

    /**
     * @param list<string> $allowedOrigins  exact origins (`https://app.example.com`), or `["*"]` for any
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders  request headers a preflight may ask for, or `["*"]` for any
     * @param list<string> $exposedHeaders  response headers client-side JavaScript may read
     */
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
        private array $allowedHeaders = ['*'],
        private array $exposedHeaders = [],
        private bool $allowCredentials = false,
        private int $maxAge = 86400,
    ) {
        if ($this->allowedOrigins === []) {
            throw new InvalidArgumentException('cors.allowed_origins needs at least one origin, or "*" for any.');
        }

        foreach ($this->allowedOrigins as $origin) {
            if ($origin !== '*' && preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?$#D', $origin) !== 1) {
                throw new InvalidArgumentException(\sprintf('cors.allowed_origins: "%s" must be "*" or a plain "https://host" or "https://host:port" with no path.', $origin));
            }
        }

        if ($this->allowCredentials && \in_array('*', $this->allowedOrigins, true)) {
            throw new InvalidArgumentException('cors.allow_credentials cannot be combined with allowed_origins: ["*"] (browsers refuse that combination); list the exact origins allowed to send credentials.');
        }

        foreach ($this->allowedMethods as $method) {
            if (preg_match('/^[A-Z]+$/D', $method) !== 1) {
                throw new InvalidArgumentException(\sprintf('cors.allowed_methods: "%s" is not a plain uppercase HTTP method.', $method));
            }
        }

        foreach ([...$this->allowedHeaders, ...$this->exposedHeaders] as $header) {
            if ($header !== '*' && preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $header) !== 1) {
                throw new InvalidArgumentException(\sprintf('cors.allowed_headers / exposed_headers: "%s" must be "*" or a plain header name.', $header));
            }
        }

        if ($this->maxAge < 0 || $this->maxAge > self::MAX_AGE_CEILING) {
            throw new InvalidArgumentException('cors.max_age must be between 0 and 31536000 seconds (one year).');
        }
    }

    /**
     * The policy configured under `cors`, or null when it is not enabled there.
     *
     * @throws InvalidArgumentException when a value is not acceptable
     */
    public static function fromConfiguration(Configuration $configuration): ?self
    {
        if (!$configuration->has('http.cors.enabled') || $configuration->get('http.cors.enabled') !== true) {
            return null;
        }

        return self::configured($configuration);
    }

    /**
     * @throws InvalidArgumentException when a value is not acceptable
     */
    public static function configured(Configuration $configuration): self
    {
        return new self(
            self::strings($configuration, 'http.cors.allowed_origins', ['*']),
            self::strings($configuration, 'http.cors.allowed_methods', ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']),
            self::strings($configuration, 'http.cors.allowed_headers', ['*']),
            self::strings($configuration, 'http.cors.exposed_headers', []),
            $configuration->has('http.cors.allow_credentials') ? self::bool($configuration, 'http.cors.allow_credentials') : false,
            $configuration->has('http.cors.max_age') ? self::int($configuration, 'http.cors.max_age') : 86400,
        );
    }

    public function allows(string $origin): bool
    {
        return $origin !== '' && (\in_array('*', $this->allowedOrigins, true) || \in_array($origin, $this->allowedOrigins, true));
    }

    /**
     * The headers a preflight response needs: the full policy, plus the one origin that asked.
     *
     * @return array<string, string>
     */
    public function preflightHeaders(string $origin, ?string $requestedHeaders): array
    {
        return [
            ...$this->originHeaders($origin),
            'Access-Control-Allow-Methods' => implode(', ', $this->allowedMethods),
            'Access-Control-Allow-Headers' => \in_array('*', $this->allowedHeaders, true) && $requestedHeaders !== null ? $requestedHeaders : implode(', ', $this->allowedHeaders),
            'Access-Control-Max-Age' => (string) $this->maxAge,
        ];
    }

    /**
     * The headers a real (non-preflight) response needs: which origin may read it, and with which
     * response headers exposed to client-side JavaScript.
     *
     * @return array<string, string>
     */
    public function responseHeaders(string $origin): array
    {
        return [
            ...$this->originHeaders($origin),
            ...($this->exposedHeaders === [] ? [] : ['Access-Control-Expose-Headers' => implode(', ', $this->exposedHeaders)]),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function originHeaders(string $origin): array
    {
        return [
            'Access-Control-Allow-Origin' => \in_array('*', $this->allowedOrigins, true) && !$this->allowCredentials ? '*' : $origin,
            'Vary' => 'Origin',
            ...($this->allowCredentials ? ['Access-Control-Allow-Credentials' => 'true'] : []),
        ];
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    private static function strings(Configuration $configuration, string $key, array $default): array
    {
        if (!$configuration->has($key)) {
            return $default;
        }

        $value = $configuration->array($key);

        if (array_filter($value, is_string(...)) !== $value || !array_is_list($value)) {
            throw new InvalidArgumentException(\sprintf('%s must be a list of strings.', $key));
        }

        return $value;
    }

    private static function bool(Configuration $configuration, string $key): bool
    {
        $value = $configuration->get($key);

        return \is_bool($value) ? $value : throw new InvalidArgumentException(\sprintf('%s must be true or false.', $key));
    }

    private static function int(Configuration $configuration, string $key): int
    {
        return $configuration->int($key);
    }
}
