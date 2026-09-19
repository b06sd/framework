<?php

declare(strict_types=1);

namespace Trunk\Http\Server;

use InvalidArgumentException;
use Trunk\Foundation\Configuration;

/**
 * Hard limits on what a request may send. They apply before application code runs; the web
 * server's and PHP's own limits (post_max_size, client_max_body_size) still apply on top.
 */
final readonly class RequestLimits
{
    public function __construct(
        public int $maxBodyBytes = 2 * 1024 * 1024,
        public int $maxFiles = 20,
        public int $maxFileBytes = 8 * 1024 * 1024,
        public int $maxJsonDepth = 16,
        public int $maxUriBytes = 8192,
    ) {
        if ($maxBodyBytes < 0 || $maxBodyBytes > 2 ** 31 || $maxFiles < 0 || $maxFiles > 1000 || $maxFileBytes < 0 || $maxFileBytes > 2 ** 32 || $maxJsonDepth < 1 || $maxJsonDepth > 512 || $maxUriBytes < 256 || $maxUriBytes > 65_536) {
            throw new InvalidArgumentException('Request limits are out of range (max_body_bytes up to 2 GB, max_files up to 1000, max_json_depth 1 to 512, max_uri_bytes 256 to 65536).');
        }
    }

    public static function fromConfiguration(Configuration $configuration): self
    {
        $int = static fn(string $key, int $default): int => $configuration->has('http.' . $key) && \is_int($configuration->get('http.' . $key)) ? $configuration->int('http.' . $key) : $default;
        $defaults = new self();

        return new self($int('max_body_bytes', $defaults->maxBodyBytes), $int('max_files', $defaults->maxFiles), $int('max_file_bytes', $defaults->maxFileBytes), $int('max_json_depth', $defaults->maxJsonDepth), $int('max_uri_bytes', $defaults->maxUriBytes));
    }
}
