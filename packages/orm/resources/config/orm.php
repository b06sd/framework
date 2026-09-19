<?php

declare(strict_types=1);

use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

// Entity maps are discovered as app/Orm/*Map.php (class App\Orm\<File>). Add other map classes to
// the list by hand if you keep them elsewhere. The list is compiled into `trunk build`, so this
// directory is only read at build time in production.
return static function (Runtime $runtime): array {
    $maps = [];

    foreach (glob($runtime->basePath . '/app/Orm/*Map.php') ?: [] as $file) {
        $maps[] = 'App\\Orm\\' . basename($file, '.php');
    }

    return [
        'mode' => $runtime->environment === Environment::Production ? 'compiled' : 'development',
        'maps' => $maps,
        'build' => $runtime->basePath . '/build',
    ];
};
