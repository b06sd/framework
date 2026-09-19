<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// Settings come from .env (LOG_LEVEL, LOG_CHANNEL). Secrets in log context (password, token,
// authorization, cookie, api_key, ...) are always redacted; add your own keys in `redact`.
return static function (Runtime $runtime): array {
    $local = $runtime->environment !== Environment::Production;

    return [
        // debug, info, notice, warning, error, critical, alert, emergency
        'level' => $runtime->variable('LOG_LEVEL', $local ? 'debug' : 'info'),
        // stderr (containers, workers), file (storage/logs/trunk-YYYY-MM-DD.log), null
        'channel' => $runtime->variable('LOG_CHANNEL', $local ? 'file' : 'stderr'),
        // json (one object per line, for log platforms) or line (human readable)
        'format' => $local ? 'line' : 'json',
        'path' => $runtime->basePath . '/storage/logs',
        // Shown as "service" on every record; use it to tell your applications apart in one log platform.
        'service' => $runtime->variable('APP_NAME', 'app'),
        'redact' => [],
    ];
};
