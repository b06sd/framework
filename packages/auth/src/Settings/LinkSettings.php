<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

/**
 * One-time links: password resets and email verification.
 */
final readonly class LinkSettings
{
    public function __construct(
        public string $table = 'trunk_auth_links',
        public int $resetTtl = 3600,
        public int $verifyTtl = 86400,
        public int $resendAfter = 60,
    ) {}
}
