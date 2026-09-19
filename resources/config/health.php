<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// /health/live and /health/ready are always on. /metrics is off until you set a token.
return static fn(Runtime $runtime): array => [
    // Set METRICS_TOKEN in .env to expose /metrics (Authorization: Bearer <token>). Needs the observability capability.
    'metrics_token' => $runtime->secret('METRICS_TOKEN', ''),
    // Show why a check is down (development only).
    'debug' => $runtime->debug,
];
