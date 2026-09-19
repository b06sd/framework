<?php

declare(strict_types=1);

// The application under test, served by `php -S` for the browser tests. It boots the real kernel
// (development container) on every request, exactly like an application entry point would.

require __DIR__ . '/../../../vendor/autoload.php';

use Trunk\Application\Application;
use Trunk\Auth\AuthModule;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernelFactory;
use Trunk\Tests\Fixtures\Auth\AuthTestModule;

$config = json_decode((string) getenv('TRUNK_BROWSER_CONFIG'), true, 32, \JSON_THROW_ON_ERROR);
$config = is_array($config) ? $config : [];
$directory = (string) getenv('TRUNK_BROWSER_DIR');
$configuration = new Configuration([
    'logging' => ['channel' => 'stderr', 'level' => 'error'],
    'database' => ['default' => 'main', 'log_queries' => false, 'migrations' => $directory, 'connections' => ['main' => ['driver' => 'sqlite', 'database' => $directory . '/app.sqlite']]],
    ...$config,
]);
$manifest = new ModuleManifest([LoggingModule::class, DiagnosticsModule::class, DatabaseModule::class, HttpModule::class, AuthModule::class, AuthTestModule::class]);
$application = new Application(new Runtime(Environment::Production, false, $directory), $configuration, $manifest);
$application->register();
$application->boot();
new HttpKernelFactory()->development($application, $manifest)->run();
