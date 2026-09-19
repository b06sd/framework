<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// Development compiles Tusk templates on demand; production uses the templates compiled by `trunk build`.
return static fn(Runtime $runtime): array => [
    'mode' => $runtime->environment === Environment::Production ? 'compiled' : 'development',
    'paths' => [$runtime->basePath . '/resources/views'],
    'cache' => $runtime->basePath . '/storage/views',
    'build' => $runtime->basePath . '/build',
];
