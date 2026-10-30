<?php

declare(strict_types=1);

// A web application with the API token page, served by `php -S` for the browser tests. It uses the
// layout, styles and script a new web project gets, and the token page template as published.

require __DIR__ . '/../../../vendor/autoload.php';

use Trunk\ApiTokens\ApiTokensModule;
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
use Trunk\Mvc\MvcModule;
use Trunk\Tests\Fixtures\Auth\AuthTestModule;
use Trunk\Tusk\TuskModule;

$stubs = __DIR__ . '/../../../packages/console/resources/stubs/web';
$path = parse_url(is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);

if ($path === '/styles.css' || $path === '/script.js') {
    header('Content-Type: ' . ($path === '/styles.css' ? 'text/css' : 'text/javascript'));
    readfile($stubs . $path . '.stub');

    return;
}

$directory = (string) getenv('TRUNK_BROWSER_DIR');
$views = $directory . '/views';

if (!is_file($views . '/account/tokens.tusk.php')) {
    @mkdir($views . '/layouts', 0o755, true);
    @mkdir($views . '/account', 0o755, true);
    file_put_contents($views . '/layouts/app.tusk.php', str_replace('%%title%%', 'Acme', (string) file_get_contents($stubs . '/layout-app.tusk.php.stub')));
    copy(__DIR__ . '/../../../packages/api-tokens/resources/views/account/tokens.tusk.php', $views . '/account/tokens.tusk.php');
}

$configuration = new Configuration([
    'logging' => ['channel' => 'stderr', 'level' => 'error'],
    'database' => ['default' => 'main', 'log_queries' => false, 'migrations' => $directory, 'connections' => ['main' => ['driver' => 'sqlite', 'database' => $directory . '/app.sqlite']]],
    'auth' => ['password' => ['memory_cost' => 8192, 'time_cost' => 1], 'session' => ['secure' => false]],
    'http' => ['security_headers' => ['enabled' => true]],
    'views' => ['mode' => 'development', 'paths' => [$views], 'cache' => $directory . '/view-cache', 'build' => $directory . '/build'],
    'api_tokens' => ['abilities' => ['read', 'write'], 'max_active' => 20],
]);
$manifest = new ModuleManifest([LoggingModule::class, DiagnosticsModule::class, DatabaseModule::class, HttpModule::class, AuthModule::class, TuskModule::class, MvcModule::class, ApiTokensModule::class, AuthTestModule::class]);
$application = new Application(new Runtime(Environment::Production, false, $directory), $configuration, $manifest);
$application->register();
$application->boot();
new HttpKernelFactory()->development($application, $manifest)->run();
