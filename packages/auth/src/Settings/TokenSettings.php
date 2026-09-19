<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

final readonly class TokenSettings
{
    public function __construct(
        public string $table = 'trunk_tokens',
        public int $ttl = 2592000,
        public int $touchInterval = 300,
    ) {}
}
