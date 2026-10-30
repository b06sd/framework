<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use RuntimeException;

/**
 * GETs a key set over https with PHP's own stream wrapper: certificates verified, no redirects, five
 * seconds at most, and at most 1 MB read.
 *
 * @internal
 */
final class HttpKeySource implements KeySource
{
    private const int MAX_BYTES = 1_048_576;

    public function fetch(string $url): string
    {
        if (!JwtSettings::isAllowedUrl($url)) {
            throw new RuntimeException(\sprintf('Refusing to fetch the key set from %s: it must be https.', $url));
        }

        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Accept: application/json\r\n", 'user_agent' => 'Trunk'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);

        if ($stream === false) {
            throw new RuntimeException(\sprintf('Cannot reach the key set at %s.', $url));
        }

        try {
            $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
            $status = \is_array($headers) && \is_string($headers[0] ?? null) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m) === 1 ? (int) $m[1] : 0;
            $body = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }

        if ($status !== 200) {
            throw new RuntimeException(\sprintf('The key set at %s answered HTTP %d.', $url, $status));
        }

        if (!\is_string($body) || \strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException(\sprintf('The key set at %s is larger than 1 MB.', $url));
        }

        return $body;
    }
}
