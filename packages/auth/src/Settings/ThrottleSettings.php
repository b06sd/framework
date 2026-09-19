<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

final readonly class ThrottleSettings
{
    public function __construct(
        public string $table = 'trunk_auth_throttles',
        public int $maxAttempts = 5,
        public int $maxAttemptsPerIp = 30,
        public int $window = 900,
        public int $maxNewSessionsPerIp = 60,
    ) {}
}
