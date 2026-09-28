<?php

declare(strict_types=1);

namespace Trunk\Testing;

use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Project\ApplicationFactory;
use Trunk\Foundation\Project\ProjectLoader;
use Trunk\Http\Kernel\HttpKernelFactory;

/**
 * Boots your real application, the same way `trunk serve` or production does, and hands back a
 * `TestClient` to drive it. `local` (the default) uses your development container; `production` needs
 * a build (`trunk build` first) and runs it exactly as production would.
 *
 *     final class HomeTest extends TestCase
 *     {
 *         public function test_the_home_page_renders(): void
 *         {
 *             TestApp::client(dirname(__DIR__))->get('/')->assertOk()->assertSee('<h1>');
 *         }
 *     }
 *
 * To exercise a database, point `DB_DATABASE` at a temporary file in `$variables`. There is no shared
 * state between calls: each one boots a fresh application, so tests never leak into each other.
 *
 * @api
 */
final class TestApp
{
    /**
     * @param array<string, string> $variables environment overrides, merged over the real environment and .env
     */
    public static function client(string $projectRoot, string $environment = 'local', array $variables = []): TestClient
    {
        $project = new ProjectLoader()->load($projectRoot);
        $factory = new ApplicationFactory();
        $runtime = $factory->runtime($project, ['APP_ENV' => $environment, 'APP_DEBUG' => '0', ...$variables]);
        $application = $factory->create($project, $runtime);

        $kernel = $runtime->environment === Environment::Production
            ? new HttpKernelFactory()->compiled($application, $project->buildDirectory())
            : new HttpKernelFactory()->development($application, new ModuleManifest($project->modules));

        return new TestClient($kernel);
    }
}
