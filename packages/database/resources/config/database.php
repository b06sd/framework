<?php

declare(strict_types=1);

use Trunk\Foundation\Runtime;

// Settings come from the environment and .env (DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE,
// DB_USERNAME, DB_PASSWORD). Never put a password in this file.
return static function (Runtime $runtime): array {
    $sqlite = $runtime->variable('DB_DATABASE', 'storage/database.sqlite') ?? 'storage/database.sqlite';

    return [
        'default' => $runtime->variable('DB_CONNECTION', 'sqlite'),
        'log_queries' => $runtime->debug,
        'migrations' => $runtime->basePath . '/database/migrations',
        'connections' => [
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => $sqlite === ':memory:' || str_starts_with($sqlite, '/') ? $sqlite : $runtime->basePath . '/' . $sqlite,
            ],
            'mysql' => [
                'driver' => 'mysql',
                'host' => $runtime->variable('DB_HOST', '127.0.0.1'),
                'port' => (int) $runtime->variable('DB_PORT', '3306'),
                'database' => $runtime->variable('DB_DATABASE', ''),
                'username' => $runtime->variable('DB_USERNAME', 'root'),
                'password' => $runtime->secret('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => $runtime->variable('DB_HOST', '127.0.0.1'),
                'port' => (int) $runtime->variable('DB_PORT', '5432'),
                'database' => $runtime->variable('DB_DATABASE', ''),
                'username' => $runtime->variable('DB_USERNAME', 'postgres'),
                'password' => $runtime->secret('DB_PASSWORD', ''),
                'sslmode' => 'prefer',
            ],
        ],
    ];
};
