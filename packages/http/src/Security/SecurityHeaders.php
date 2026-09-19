<?php

declare(strict_types=1);

namespace Trunk\Http\Security;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Trunk\Foundation\Configuration;
use Trunk\Http\Message\Message;

/**
 * The response headers that make browsers refuse whole classes of attack, as one policy. Defaults:
 * `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy`, cross-origin
 * opener/resource isolation, a `Permissions-Policy` that turns the camera, microphone and location
 * off, a `Content-Security-Policy` limited to the always-safe directives (`frame-ancestors`,
 * `base-uri`, `form-action`), and `Strict-Transport-Security`, which is sent **only on https
 * requests** so a plain-http development server is never pinned to https.
 *
 * A header the response already carries is never replaced (a handler that needs a looser policy for
 * one page sets its own). Values come from `security_headers` in config/http.php and are validated at
 * build time; a value containing a line break, or any character outside printable ASCII, is refused.
 * Set `enabled` to true and the kernel applies the policy to every response, error pages included;
 * use `SecurityHeadersMiddleware` to apply a different policy to one route group.
 *
 * @api
 */
final readonly class SecurityHeaders
{
    /** @var array<string, array{string, string}> lowercase name => [name, value], for plain http */
    private array $plain;

    /** @var array<string, array{string, string}> the same plus Strict-Transport-Security, for https */
    private array $secure;

    /**
     * @param array<string, string> $headers extra or replacement headers by name (a non-empty value); merged over the defaults
     * @param list<string>          $omit    default headers to leave out, by name
     * @param int                   $hstsSeconds 0 turns Strict-Transport-Security off
     */
    public function __construct(array $headers = [], array $omit = [], int $hstsSeconds = 31_536_000, bool $hstsIncludeSubDomains = true, bool $hstsPreload = false)
    {
        $merged = [...self::defaults(), ...$headers];

        foreach ($omit as $name) {
            unset($merged[$name]);
        }

        foreach ($merged as $name => $value) {
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1 || preg_match('/^[\x20-\x7E]{1,2048}$/D', $value) !== 1) {
                throw new InvalidArgumentException(\sprintf('The security header "%s" must have a name of letters, digits and hyphens and a value of printable ASCII (no line breaks, at most 2048 characters).', $name));
            }
        }

        if ($hstsSeconds < 0 || $hstsSeconds > 63_072_000) {
            throw new InvalidArgumentException('The HSTS max-age is 0 (off) up to two years, in seconds.');
        }

        if ($hstsPreload && (!$hstsIncludeSubDomains || $hstsSeconds < 31_536_000)) {
            throw new InvalidArgumentException('HSTS preload needs includeSubDomains and a max-age of at least one year.');
        }

        $plain = [];

        foreach ($merged as $name => $value) {
            $plain[strtolower($name)] = [$name, $value];
        }

        $this->plain = $plain;
        $this->secure = $hstsSeconds === 0 ? $plain : [...$plain, 'strict-transport-security' => ['Strict-Transport-Security', 'max-age=' . $hstsSeconds . ($hstsIncludeSubDomains ? '; includeSubDomains' : '') . ($hstsPreload ? '; preload' : '')]];
    }

    /**
     * The policy configured under `security_headers`, or null when it is not enabled there.
     *
     * @throws InvalidArgumentException when a value is not acceptable
     */
    public static function fromConfiguration(Configuration $configuration): ?self
    {
        if (!$configuration->has('http.security_headers.enabled') || $configuration->get('http.security_headers.enabled') !== true) {
            return null;
        }

        return self::configured($configuration);
    }

    /**
     * The policy from `security_headers` whether or not the kernel-wide switch is on (for the middleware,
     * which a route group opts into by name).
     */
    public static function configured(Configuration $configuration): self
    {
        $headers = [];
        $omit = [];
        $map = [
            'content_type_options' => 'X-Content-Type-Options',
            'frame_options' => 'X-Frame-Options',
            'referrer_policy' => 'Referrer-Policy',
            'cross_origin_opener_policy' => 'Cross-Origin-Opener-Policy',
            'cross_origin_resource_policy' => 'Cross-Origin-Resource-Policy',
            'permissions_policy' => 'Permissions-Policy',
            'content_security_policy' => 'Content-Security-Policy',
        ];

        foreach ($map as $key => $header) {
            if (!$configuration->has('http.security_headers.' . $key)) {
                continue;
            }

            $value = $configuration->get('http.security_headers.' . $key);

            if ($value === false || $value === null) {
                $omit[] = $header;
            } elseif (\is_string($value)) {
                $headers[$header] = $value;
            } else {
                throw new InvalidArgumentException(\sprintf('http.security_headers.%s must be a string, or false to leave the header out.', $key));
            }
        }

        if (isset($headers['X-Frame-Options']) && !\in_array($headers['X-Frame-Options'], ['DENY', 'SAMEORIGIN'], true)) {
            throw new InvalidArgumentException('http.security_headers.frame_options must be DENY or SAMEORIGIN (use content_security_policy frame-ancestors for anything else).');
        }

        $hsts = $configuration->has('http.security_headers.hsts') ? $configuration->get('http.security_headers.hsts') : 31_536_000;

        if ($hsts === false) {
            $hsts = 0;
        }

        if (!\is_int($hsts)) {
            throw new InvalidArgumentException('http.security_headers.hsts must be a number of seconds, or false to turn it off.');
        }

        $subdomains = $configuration->has('http.security_headers.hsts_include_subdomains') ? $configuration->get('http.security_headers.hsts_include_subdomains') : true;
        $preload = $configuration->has('http.security_headers.hsts_preload') ? $configuration->get('http.security_headers.hsts_preload') : false;

        if (!\is_bool($subdomains) || !\is_bool($preload)) {
            throw new InvalidArgumentException('http.security_headers.hsts_include_subdomains and hsts_preload must be true or false.');
        }

        return new self($headers, $omit, $hsts, $subdomains, $preload);
    }

    /**
     * Adds the policy's headers to a response, skipping any the response already has.
     *
     * @param bool $secureRequest whether the request came over https (only then is HSTS sent)
     */
    public function apply(ResponseInterface $response, bool $secureRequest): ResponseInterface
    {
        $prepared = $secureRequest ? $this->secure : $this->plain;

        if ($response instanceof Message) {
            // The values were validated once, at construction: add them with a single copy of the response.
            return $response->withMissingHeaders($prepared);
        }

        foreach ($prepared as [$name, $value]) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "frame-ancestors 'none'; base-uri 'self'; form-action 'self'",
        ];
    }
}
