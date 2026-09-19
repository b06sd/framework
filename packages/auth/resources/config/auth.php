<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// Authentication and authorization. Nothing here is a secret: session ids and tokens are random and
// stored only as hashes. Run `trunk auth:table` and `trunk migrate` to create the tables.
return static fn(Runtime $runtime): array => [
    'password' => [
        // argon2id (recommended) or bcrypt. The build fails if argon2id is chosen but PHP lacks it.
        'algorithm' => 'argon2id',
        'memory_cost' => 65536,
        'time_cost' => 4,
        'threads' => 1,
        // New passwords must be at least this long. Any password longer than max_length is refused
        // (hashing very long inputs is a denial-of-service vector).
        'min_length' => 12,
        'max_length' => 1024,
    ],
    'users' => [
        // Used by the built-in database provider; write your own UserProvider for other storage.
        'table' => 'users',
        'id' => 'id',
        'identifier' => 'email',
        'password' => 'password',
        // Changing this value signs a user out everywhere (change it whenever the password changes).
        'session_version' => 'session_version',
    ],
    'session' => [
        // database (works across servers), file, or array (tests).
        'store' => 'database',
        'table' => 'trunk_sessions',
        'path' => $runtime->basePath . '/storage/sessions',
        'cookie' => 'session',
        // Seconds without a request before a session ends, and the most a session may ever last.
        'idle_timeout' => 7200,
        'lifetime' => 43200,
        // Lax, Strict or None (None needs secure = true).
        'same_site' => 'Lax',
        // Cookies are Secure (and __Host- prefixed) in production. Local development is plain http.
        'secure' => $runtime->environment === Environment::Production,
    ],
    'tokens' => [
        'table' => 'trunk_tokens',
        // Default lifetime of a new token in seconds (30 days); 0 means it never expires.
        'ttl' => 2592000,
        // last_used_at is written at most this often per token, to avoid a write per request.
        'touch_interval' => 300,
    ],
    'throttle' => [
        'table' => 'trunk_auth_throttles',
        // Failed logins allowed per identifier+address, and per address alone, in the window.
        'max_attempts' => 5,
        'max_attempts_per_ip' => 30,
        'window' => 900,
        // Sessions one address may start without signing in (a page that shows a CSRF token starts one)
        // in the window; beyond it the request is refused with 429 and nothing is stored.
        'max_new_sessions_per_ip' => 60,
    ],
    // Where RequireLogin sends browsers that are not signed in (API clients get 401).
    'login_path' => '/login',
];
