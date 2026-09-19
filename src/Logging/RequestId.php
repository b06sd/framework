<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * Request ids look like `req_01K8ZQ3T5W9Y2M4N6P8R0S1V3X`: a ULID (48-bit millisecond time, then 80
 * random bits, Crockford base32), so they sort by time and cannot collide in practice. Ids arriving
 * from a client or gateway are accepted only if they match a strict, log-safe pattern; anything
 * else is replaced, so a caller can never inject line breaks, escape sequences or huge values.
 */
final class RequestId
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const string INBOUND = '/^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$/D';

    public static function generate(string $prefix = 'req'): string
    {
        $time = substr(pack('J', (int) (microtime(true) * 1000)), 2);

        return $prefix . '_' . self::encode($time . random_bytes(10));
    }

    /**
     * @return string|null the id if it is safe to use as is
     */
    public static function accept(?string $inbound): ?string
    {
        return $inbound !== null && preg_match(self::INBOUND, $inbound) === 1 ? $inbound : null;
    }

    /**
     * Crockford base32 of the 16 bytes behind two leading zero bits (130 bits, 26 characters), the
     * standard ULID layout. Bits are moved through a small integer accumulator: building strings of
     * "0" and "1" characters instead costs several times more per request.
     */
    private static function encode(string $bytes): string
    {
        $accumulator = 0;
        $bits = 2;
        $encoded = '';

        /** @var array<int, int> $values */
        $values = unpack('C*', $bytes) ?: [];

        foreach ($values as $byte) {
            $accumulator = ($accumulator << 8) | $byte;
            $bits += 8;

            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::ALPHABET[($accumulator >> $bits) & 31];
            }

            $accumulator &= (1 << $bits) - 1;
        }

        return $encoded;
    }
}
