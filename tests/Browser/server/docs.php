<?php

declare(strict_types=1);

// An API with the openapi capability, served by `php -S` for the browser tests of GET /docs (Swagger UI).

require __DIR__ . '/../../../vendor/autoload.php';

use Trunk\Application\Application;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernelFactory;
use Trunk\OpenApi\OpenApiModule;
use Trunk\Tests\Fixtures\OpenApi\OpenApiRoutesModule;
use Trunk\Validation\ValidationModule;

$directory = (string) getenv('TRUNK_BROWSER_DIR');
$modules = [HttpModule::class, ValidationModule::class, OpenApiModule::class, OpenApiRoutesModule::class];

if (!is_file($directory . '/trunk.php')) {
    file_put_contents($directory . '/trunk.php', '<?php return ' . var_export(['name' => 'shop', 'type' => 'api', 'modules' => $modules], true) . ';');
}

$configuration = new Configuration([
    'http' => ['security_headers' => ['enabled' => true]],
    'validation' => ['mode' => 'development'],
    'openapi' => ['title' => 'Shop API', 'version' => '1.0.0', 'serve' => true, 'mode' => 'development', 'build' => $directory, 'security' => [Trunk\Tests\Fixtures\OpenApi\RequireApiKey::class => ['apiKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key']]]],
]);
$manifest = new ModuleManifest($modules);
$application = new Application(new Runtime(Environment::Local, false, $directory), $configuration, $manifest);
$application->register();
$application->boot();
new HttpKernelFactory()->development($application, $manifest)->run();
