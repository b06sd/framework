<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// The limit RateLimitMiddleware enforces on whichever routes list it (RATE_LIMIT_MAX requests per
// RATE_LIMIT_WINDOW seconds, per client address). For a different limit on a different route, inject
// RateLimiter directly instead of this middleware.
return static fn(Runtime $runtime): array => [
    'table' => 'trunk_rate_limits',
    'max' => (int) $runtime->variable('RATE_LIMIT_MAX', '60'),
    'window' => (int) $runtime->variable('RATE_LIMIT_WINDOW', '60'),
];
