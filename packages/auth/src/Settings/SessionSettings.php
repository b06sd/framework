<?php

declare(strict_types=1);

namespace Trunk\Auth\Settings;

final readonly class SessionSettings
{
    public function __construct(
        public string $store = 'database',
        public string $table = 'trunk_sessions',
        public string $path = '',
        public string $cookie = 'session',
        public int $idleTimeout = 7200,
        public int $lifetime = 43200,
        public string $sameSite = 'Lax',
        public bool $secure = true,
    ) {}

    /** The cookie name as sent: `__Host-` prefixed when the cookie is Secure, which pins it to this host. */
    public function cookieName(): string
    {
        return ($this->secure ? '__Host-' : '') . $this->cookie;
    }
}
