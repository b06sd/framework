<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Settings come from the environment and .env: CACHE_DRIVER (file | array | null), CACHE_PATH, CACHE_PREFIX.
return static fn(Runtime $runtime): array => [
    'driver' => $runtime->variable('CACHE_DRIVER', 'file'),
    'path' => $runtime->variable('CACHE_PATH', $runtime->basePath . '/storage/cache'),
    'prefix' => $runtime->variable('CACHE_PREFIX', ''),
];
