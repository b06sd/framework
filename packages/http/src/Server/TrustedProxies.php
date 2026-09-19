<?php

declare(strict_types=1);

namespace Trunk\Http\Server;

use InvalidArgumentException;
use Trunk\Foundation\Configuration;

/**
 * The reverse proxies whose X-Forwarded-* headers may be believed, as a list of IPv4/IPv6 addresses
 * or CIDR ranges. Empty (the default) means no forwarded header is ever trusted. Anyone can send
 * these headers, so they are honoured only when the TCP peer itself is a listed proxy.
 */
final readonly class TrustedProxies
{
    /** @var list<array{string, int}> packed address and prefix length */
    private array $ranges;

    /**
     * @param list<string> $cidrs e.g. "10.0.0.0/8", "192.168.1.5", "fd00::/8"
     */
    public function __construct(array $cidrs = [])
    {
        $ranges = [];

        foreach ($cidrs as $cidr) {
            $ranges[] = self::parse($cidr);
        }

        $this->ranges = $ranges;
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        if (!$configuration->has('http.trusted_proxies')) {
            return new self();
        }

        $value = $configuration->get('http.trusted_proxies');

        if (!\is_array($value) || !array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
            throw new InvalidArgumentException('http.trusted_proxies must be a list of IP addresses or CIDR ranges.');
        }

        /** @var list<string> $value */
        return new self($value);
    }

    public function isEmpty(): bool
    {
        return $this->ranges === [];
    }

    public function isTrusted(string $address): bool
    {
        $packed = filter_var($address, \FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false) {
            return false;
        }

        foreach ($this->ranges as [$network, $prefix]) {
            if (\strlen($network) === \strlen($packed) && self::matches($packed, $network, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{string, int}
     */
    private static function parse(string $cidr): array
    {
        $parts = explode('/', $cidr, 2);
        $address = $parts[0];
        $length = $parts[1] ?? null;
        $packed = filter_var($address, \FILTER_VALIDATE_IP) === false ? false : inet_pton($address);

        if ($packed === false) {
            throw new InvalidArgumentException(\sprintf('http.trusted_proxies: "%s" is not an IP address or CIDR range.', $cidr));
        }

        $bits = \strlen($packed) * 8;

        if ($length !== null && (!ctype_digit($length) || (int) $length > $bits)) {
            throw new InvalidArgumentException(\sprintf('http.trusted_proxies: "%s" has an invalid prefix length (0 to %d).', $cidr, $bits));
        }

        return [$packed, $length === null ? $bits : (int) $length];
    }

    private static function matches(string $address, string $network, int $prefix): bool
    {
        $whole = intdiv($prefix, 8);

        if ($whole > 0 && substr($address, 0, $whole) !== substr($network, 0, $whole)) {
            return false;
        }

        $rest = $prefix % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (\ord($address[$whole]) & $mask) === (\ord($network[$whole]) & $mask);
    }
}
