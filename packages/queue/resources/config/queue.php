<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// Jobs are discovered as app/Jobs/*.php (class App\Jobs\<File>). Add other job classes to the list
// by hand if you keep them elsewhere. `trunk build` compiles them; in production only that build
// runs. Settings come from .env (QUEUE_CONNECTION names one of the database.connections).
return static function (Runtime $runtime): array {
    $jobs = [];

    foreach (glob($runtime->basePath . '/app/Jobs/*.php') ?: [] as $file) {
        $jobs[] = 'App\\Jobs\\' . basename($file, '.php');
    }

    return [
        'mode' => $runtime->environment === Environment::Production ? 'compiled' : 'development',
        'jobs' => $jobs,
        'build' => $runtime->basePath . '/build',
        'connection' => $runtime->variable('QUEUE_CONNECTION', $runtime->variable('DB_CONNECTION', 'sqlite')) ?? 'sqlite',
        'table' => 'trunk_jobs',
        'failed_table' => 'trunk_failed_jobs',
        // Largest accepted payload, in bytes. Pass ids, not documents.
        'max_payload' => 65536,
        // Seconds before a claimed job that never finished may be claimed again. Must exceed every job timeout by 30.
        'visibility_timeout' => 600,
        // Long-running worker limits (a worker exits cleanly at any limit; run it under a supervisor that restarts it).
        // 0 disables a limit. gc_interval: run a garbage-collection cycle every N jobs (never per job).
        'worker' => [
            'memory_limit' => '256M',
            'growth_warn' => '64M',
            'max_jobs' => 1000,
            'max_runtime' => 3600,
            'gc_interval' => 100,
            'sleep' => 3,
        ],
        // Failure messages can contain user data; they are stored only in development.
        'store_failure_messages' => $runtime->debug,
    ];
};
