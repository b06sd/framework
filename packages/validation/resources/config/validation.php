<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// Request classes are discovered as app/Requests/*.php (class App\Requests\<File>). Add other request
// classes to the list by hand if you keep them elsewhere. The list is compiled by `trunk build`, so
// the directory is only read at build time in production.
return static function (Runtime $runtime): array {
    $requests = [];

    foreach (glob($runtime->basePath . '/app/Requests/*.php') ?: [] as $file) {
        $requests[] = 'App\\Requests\\' . basename($file, '.php');
    }

    return [
        'mode' => $runtime->environment === Environment::Production ? 'compiled' : 'development',
        'requests' => $requests,
        'build' => $runtime->basePath . '/build',
    ];
};
