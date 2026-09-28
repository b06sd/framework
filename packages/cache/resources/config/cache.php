<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Settings come from the environment and .env: CACHE_DRIVER (file | array | null | redis), CACHE_PATH,
// CACHE_PREFIX. The `redis` driver needs predis/predis (composer require predis/predis) and is the
// only one that stays correct once an application runs on more than one server: file and array
// caches are local to the box that wrote them.
return static fn(Runtime $runtime): array => [
    'driver' => $runtime->variable('CACHE_DRIVER', 'file'),
    'path' => $runtime->variable('CACHE_PATH', $runtime->basePath . '/storage/cache'),
    'prefix' => $runtime->variable('CACHE_PREFIX', ''),
    'redis' => [
        'host' => $runtime->variable('CACHE_REDIS_HOST', '127.0.0.1'),
        'port' => (int) $runtime->variable('CACHE_REDIS_PORT', '6379'),
        'password' => $runtime->secret('CACHE_REDIS_PASSWORD', ''),
        'database' => (int) $runtime->variable('CACHE_REDIS_DATABASE', '0'),
    ],
];
