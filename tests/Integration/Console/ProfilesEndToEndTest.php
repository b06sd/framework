<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * Every test creates a project with the real `trunk new`, then drives the real `trunk` binary and
 * `public/index.php` in subprocesses, in both production (compiled) and development mode.
 */
final class ProfilesEndToEndTest extends TestCase
{
    /** @var list<ScaffoldedProject> */
    private array $projects = [];

    protected function tearDown(): void
    {
        foreach ($this->projects as $project) {
            $project->cleanUp();
        }
    }

    public function test_an_api_project_builds_without_any_view_machinery_and_serves_json_in_both_modes(): void
    {
        // Arrange
        $project = $this->project('customer-api', 'api');

        // Act
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [, $production] = $project->request('GET', '/api/customers/2', 'production');
        [, $development] = $project->request('GET', '/api/customers/2', 'local');
        [, $missing] = $project->request('GET', '/api/customers/99', 'production');

        // Assert
        self::assertSame(0, $buildCode, $buildOut);
        self::assertFileExists($project->directory . '/build/routes.php');
        self::assertFileDoesNotExist($project->directory . '/build/views.php');
        self::assertSame('{"id":2,"name":"Grace Hopper","email":"grace@example.com"}', $production);
        self::assertSame($production, $development);
        self::assertSame('Not Found', $missing);
    }

    public function test_a_web_project_renders_tusk_views_from_the_compiled_build_and_from_source(): void
    {
        // Arrange
        $project = $this->project('shop', 'web');

        // Act
        [$buildCode] = $project->trunk(['build']);
        [, $production] = $project->request('GET', '/', 'production');
        [, $development] = $project->request('GET', '/', 'local');

        // Assert
        self::assertSame(0, $buildCode);
        self::assertFileExists($project->directory . '/build/views.php');
        self::assertStringContainsString('<title>Shop</title>', $production);
        self::assertStringContainsString('<h1>Shop</h1>', $production);
        // The compiled build and the source views render the same page; the only difference is the label that
        // names the environment.
        self::assertStringContainsString('>Production</span>', $production);
        self::assertStringContainsString('>Local</span>', $development);
        self::assertSame($production, str_replace('>Local</span>', '>Production</span>', $development));
        // The default layout ships its own styles and script, links them from the site root, and has no icons.
        self::assertStringContainsString('<link rel="stylesheet" href="/styles.css">', $production);
        self::assertStringContainsString('<script src="/script.js"></script>', $production);
        self::assertStringNotContainsString('<svg', $production);
        self::assertFileExists($project->directory . '/public/styles.css');
        self::assertFileExists($project->directory . '/public/script.js');
    }

    public function test_a_self_contained_project_serves_web_and_api_from_one_application(): void
    {
        // Arrange
        $project = $this->project('crm', 'self-contained');

        // Act
        [$buildCode] = $project->trunk(['build']);
        [, $page] = $project->request('GET', '/', 'production');
        [, $json] = $project->request('GET', '/api/customers/1', 'production');

        // Assert
        self::assertSame(0, $buildCode);
        self::assertStringContainsString('<h1>Crm</h1>', $page);
        self::assertSame('{"id":1,"name":"Ada Lovelace","email":"ada@example.com"}', $json);
    }

    public function test_a_cli_project_runs_its_command_and_compiles_no_http_artifacts(): void
    {
        // Arrange
        $project = $this->project('importer', 'cli');

        // Act
        [$buildCode] = $project->trunk(['build']);
        [$productionCode, $production] = $project->trunk(['customers:import'], null, ['APP_ENV' => 'production']);
        [$developmentCode, $development] = $project->trunk(['customers:import'], null, ['APP_ENV' => 'local']);

        // Assert
        self::assertSame(0, $buildCode);
        self::assertFileDoesNotExist($project->directory . '/build/routes.php');
        self::assertSame([0, 0], [$productionCode, $developmentCode]);
        self::assertSame("✓ Imported 3 customers.\n", $production);
        self::assertSame($production, $development);
    }

    public function test_a_worker_project_runs_queued_jobs_each_in_its_own_scope_in_both_modes(): void
    {
        // Arrange
        $project = $this->project('notifier', 'worker');
        file_put_contents($project->directory . '/.env', "LOG_CHANNEL=file\nLOG_LEVEL=debug\n", FILE_APPEND);
        [$tableCode] = $project->trunk(['queue:table']);
        [$migrateCode, $migrateOut] = $project->trunk(['migrate']);

        // Act
        [$enqueueCode] = $project->trunk(['welcome:enqueue']);
        [$devCode, $devOut] = $project->trunk(['queue:work', '--stop-when-empty']);
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [$enqueueProd] = $project->trunk(['welcome:enqueue'], null, ['APP_ENV' => 'production']);
        [$prodCode, $prodOut] = $project->trunk(['queue:work', '--stop-when-empty'], null, ['APP_ENV' => 'production']);
        $jobIds = [];
        foreach (glob($project->directory . '/storage/logs/trunk-*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (str_contains($line, 'Welcome sent to user') && preg_match('/"requestId":"(job_\d+)".*"kind":"job"/', $line, $m) === 1) {
                    $jobIds[] = $m[1];
                }
            }
        }

        // Assert
        self::assertSame([0, 0, 0], [$tableCode, $migrateCode, $enqueueCode], $migrateOut);
        self::assertSame(0, $devCode, $devOut);
        self::assertStringContainsString('2 succeeded', $devOut);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertSame([0, 0], [$enqueueProd, $prodCode]);
        self::assertStringContainsString('2 succeeded', $prodOut);
        self::assertCount(4, $jobIds, 'every job logged once, in development (line format) and production (JSON)');
        self::assertCount(4, array_unique($jobIds), 'every job ran with its own job context id');
    }

    public function test_production_fails_closed_until_the_application_is_built(): void
    {
        // Arrange
        $project = $this->project('unbuilt', 'api');

        // Act
        [, $body, $error] = $project->request('GET', '/api/customers', 'production');
        [, $debugBody] = $project->request('GET', '/api/customers', 'production', debug: true);
        [$cliCode, , $cliError] = $project->trunk(['list'], null, ['APP_ENV' => 'production']);

        // Assert
        self::assertSame('Internal Server Error', $body);
        self::assertStringContainsString('has not been built for production', $error);
        self::assertSame('Internal Server Error', $debugBody);
        self::assertSame(0, $cliCode);
        self::assertSame('', $cliError);
    }

    public function test_generators_produce_code_that_works_after_a_rebuild(): void
    {
        // Arrange
        $project = $this->project('grow', 'api');
        [$makeCode] = $project->trunk(['make:controller', 'PingController']);
        file_put_contents($project->directory . '/routes/api.php', str_replace("    \$routes->group('/api'", "    \$routes->get('/ping', [\\App\\Controllers\\PingController::class, 'index']);\n    \$routes->group('/api'", (string) file_get_contents($project->directory . '/routes/api.php')));

        // Act
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [, $body] = $project->request('GET', '/ping', 'production');

        // Assert
        self::assertSame(0, $makeCode);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertSame('{"message":"PingController works"}', $body);
    }

    public function test_doctor_route_list_and_help_describe_the_project(): void
    {
        // Arrange
        $project = $this->project('inspect', 'web');

        // Act
        [$doctorCode, $doctor] = $project->trunk(['doctor'], null, ['APP_ENV' => 'local']);
        [, $routes] = $project->trunk(['route:list']);
        [, $help] = $project->trunk(['help', 'make:service']);

        // Assert
        self::assertSame(0, $doctorCode, $doctor);
        self::assertStringContainsString('Project is healthy.', $doctor);
        self::assertStringContainsString('+ Tusk - Tusk templates', $doctor);
        self::assertStringContainsString('- Console', $doctor);
        self::assertStringContainsString('HomeController::index', $routes);
        self::assertStringContainsString('Usage: trunk make:service <Name>', $help);
    }

    public function test_errors_show_technical_details_only_when_asked(): void
    {
        // Arrange
        $project = $this->project('quiet', 'api');
        $project->trunk(['make:service', 'Twice']);

        // Act
        [$code, , $plain] = $project->trunk(['make:service', 'Twice']);
        [, , $verbose] = $project->trunk(['make:service', 'Twice', '-v']);

        // Assert
        self::assertSame(1, $code);
        self::assertStringContainsString('will not overwrite', $plain);
        self::assertStringNotContainsString('#0', $plain);
        self::assertStringContainsString('#0', $verbose);
    }

    public function test_settings_come_from_the_env_file_and_the_real_environment_overrides_them(): void
    {
        // Arrange
        $project = $this->project('settings', 'api');

        // Act
        [, $fromEnvFile] = $project->request('GET', '/api/customers/1', null);
        [, $overridden, $overriddenError] = $project->request('GET', '/api/customers/1', 'production');
        unlink($project->directory . '/.env');
        [, $withoutEnvFile, $withoutError] = $project->request('GET', '/api/customers/1', null);

        // Assert
        self::assertSame('{"id":1,"name":"Ada Lovelace","email":"ada@example.com"}', $fromEnvFile);
        self::assertSame('Internal Server Error', $overridden);
        self::assertStringContainsString('has not been built for production', $overriddenError);
        self::assertSame('Internal Server Error', $withoutEnvFile);
        self::assertStringContainsString('has not been built for production', $withoutError);
    }

    public function test_the_env_file_is_git_ignored_and_config_files_read_it(): void
    {
        // Arrange
        $project = $this->project('conf', 'web');
        file_put_contents($project->directory . '/.env', "APP_NAME=\"Configured Name\"\nAPP_ENV=local\n");

        // Act
        $example = (string) file_get_contents($project->directory . '/.env.example');
        $ignore = (string) file_get_contents($project->directory . '/.gitignore');
        [$doctorCode, $doctor] = $project->trunk(['doctor']);
        [$buildCode] = $project->trunk(['build']);
        $compiled = require $project->directory . '/build/config.php';

        // Assert
        self::assertStringContainsString('APP_PORT=8006', $example);
        self::assertStringContainsString('.env', $ignore);
        self::assertStringContainsString('!.env.example', $ignore);
        self::assertSame(0, $doctorCode, $doctor);
        self::assertStringContainsString('.env found', $doctor);
        self::assertSame(0, $buildCode);
        self::assertIsArray($compiled);
        self::assertSame(['name' => 'Configured Name'], $compiled['app']);
    }

    public function test_serve_help_documents_the_default_port(): void
    {
        // Arrange
        $project = $this->project('ports', 'api');

        // Act
        [, $help] = $project->trunk(['help', 'serve']);
        [$code, , $error] = $project->trunk(['serve', '--host=bad host']);

        // Assert
        self::assertStringContainsString('default 8006', $help);
        self::assertSame(1, $code);
        self::assertStringContainsString('not a valid host', $error);
    }

    public function test_package_list_and_install_enable_the_cache_and_a_service_can_use_it_in_both_modes(): void
    {
        // Arrange
        $project = $this->project('cached', 'web');
        [, $list] = $project->trunk(['package:list']);

        // Act
        [$installCode, $installOut] = $project->trunk(['package:install', 'cache']);
        [$againCode, $againOut] = $project->trunk(['package:install', 'cache']);
        file_put_contents($project->directory . '/app/Controllers/GreetingController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Psr\\SimpleCache\\CacheInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\n\nfinal readonly class GreetingController\n{\n    public function __construct(private CacheInterface \$cache, private ResponseBuilder \$responses) {}\n\n    public function show(): ResponseInterface\n    {\n        return \$this->responses->text((string) \$this->cache->get('greeting', 'miss') . '/' . (\$this->cache->set('greeting', 'hit', 60) ? 'stored' : 'failed'));\n    }\n}\n");
        file_put_contents($project->directory . '/routes/web.php', str_replace("    \$routes->get('/', [HomeController::class, 'index'], 'home');", "    \$routes->get('/', [HomeController::class, 'index'], 'home');\n    \$routes->get('/greeting', [\\App\\Controllers\\GreetingController::class, 'show']);", (string) file_get_contents($project->directory . '/routes/web.php')));
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [, $first] = $project->request('GET', '/greeting', 'production');
        [, $second] = $project->request('GET', '/greeting', 'production');

        // Assert
        self::assertMatchesRegularExpression('/cache\s+available/', $list);
        self::assertSame(0, $installCode, $installOut);
        self::assertStringContainsString('Created config/cache.php.', $installOut);
        self::assertStringContainsString('\Trunk\Cache\CacheModule::class', (string) file_get_contents($project->directory . '/trunk.php'));
        self::assertStringContainsString('CACHE_DRIVER=file', (string) file_get_contents($project->directory . '/.env'));
        self::assertFileExists($project->directory . '/config/cache.php');
        self::assertSame(0, $againCode);
        self::assertStringContainsString('already enabled', $againOut);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertStringContainsString('Compiled capabilities: http, logging, diagnostics, tusk, mvc, cache.', $buildOut);
        self::assertSame('miss/stored', $first);
        self::assertSame('hit/stored', $second);
        self::assertCount(1, glob($project->directory . '/storage/cache/*/*.cache') ?: []);
    }

    public function test_removing_a_capability_that_others_need_is_refused_and_removal_prunes_the_build(): void
    {
        // Arrange
        $project = $this->project('pruned', 'self-contained');

        // Act
        [$refusedCode, , $refusedError] = $project->trunk(['package:remove', 'tusk']);
        [$removeCode] = $project->trunk(['package:remove', 'cache']);
        [$buildCode, $buildOut] = $project->trunk(['build']);

        // Assert
        self::assertSame(1, $refusedCode);
        self::assertStringContainsString('"mvc" requires it', $refusedError);
        self::assertSame(0, $removeCode);
        self::assertStringNotContainsString('CacheModule', (string) file_get_contents($project->directory . '/trunk.php'));
        self::assertSame(0, $buildCode);
        self::assertStringContainsString('Not compiled (not enabled): validation, console, cache, database, orm, queue, observability, auth, health.', $buildOut);
    }

    public function test_doctor_and_build_report_a_missing_requirement_with_the_fix(): void
    {
        // Arrange
        $project = $this->project('broken', 'web');
        $manifest = (string) file_get_contents($project->directory . '/trunk.php');
        file_put_contents($project->directory . '/trunk.php', str_replace("        \\Trunk\\Tusk\\TuskModule::class,\n", '', $manifest));

        // Act
        [$doctorCode, $doctor] = $project->trunk(['doctor']);
        [$buildCode, , $buildError] = $project->trunk(['build']);
        [$installCode] = $project->trunk(['package:install', 'tusk']);
        [$fixedBuild] = $project->trunk(['build']);

        // Assert
        self::assertSame(1, $doctorCode);
        self::assertStringContainsString('MVC needs "tusk", which is not enabled. Fix: trunk package:install tusk', $doctor);
        self::assertSame(1, $buildCode);
        self::assertStringContainsString('Fix: trunk package:install tusk', $buildError);
        self::assertSame(0, $installCode);
        self::assertSame(0, $fixedBuild);
    }

    public function test_an_api_project_can_grow_a_web_interface_by_installing_capabilities(): void
    {
        // Arrange
        $project = $this->project('grows', 'api');

        // Act
        [$code, $out] = $project->trunk(['package:install', 'mvc']);
        [$doctorCode, $doctor] = $project->trunk(['doctor']);

        // Assert
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('Enabled Tusk (required).', $out);
        self::assertStringContainsString('Created config/views.php.', $out);
        self::assertDirectoryExists($project->directory . '/resources/views');
        self::assertSame(0, $doctorCode, $doctor);
        self::assertStringContainsString('+ MVC', $doctor);
    }

    public function test_cache_clear_appears_only_when_both_cache_and_console_are_enabled_and_works_in_both_modes(): void
    {
        // Arrange
        $project = $this->project('clearing', 'web');
        $project->trunk(['package:install', 'cache']);
        [$missingCode] = $project->trunk(['cache:clear']);
        $project->trunk(['package:install', 'console']);
        $store = new \Trunk\Cache\Store\FileStore($project->directory . '/storage/cache', new \Trunk\Support\SystemClock());
        $store->write('leftover', 'data', null);
        $files = static fn(): array => glob($project->directory . '/storage/cache/*/*.cache') ?: [];
        $before = \count($files());

        // Act
        [$devCode, $devOut] = $project->trunk(['cache:clear'], null, ['APP_ENV' => 'local']);
        $afterDev = \count($files());
        $store->write('leftover', 'data', null);
        [$buildCode] = $project->trunk(['build']);
        [$prodCode, $prodOut] = $project->trunk(['cache:clear'], null, ['APP_ENV' => 'production']);
        $afterProd = \count($files());

        // Assert
        self::assertSame(2, $missingCode);
        self::assertStringContainsString('\Trunk\Cache\Console\CacheConsoleModule::class', (string) file_get_contents($project->directory . '/trunk.php'));
        self::assertSame(1, $before);
        self::assertSame([0, 0], [$devCode, $afterDev]);
        self::assertStringContainsString('Application cache cleared.', $devOut);
        self::assertSame(0, $buildCode);
        self::assertSame([0, 0], [$prodCode, $afterProd]);
        self::assertStringContainsString('Application cache cleared.', $prodOut);
    }

    public function test_doctor_flags_a_missing_integration_and_package_sync_repairs_it(): void
    {
        // Arrange
        $project = $this->project('syncing', 'web');
        $project->trunk(['package:install', 'cache']);
        $project->trunk(['package:install', 'console']);
        $manifest = (string) file_get_contents($project->directory . '/trunk.php');
        file_put_contents($project->directory . '/trunk.php', str_replace("        \\Trunk\\Cache\\Console\\CacheConsoleModule::class,\n", '', $manifest));

        // Act
        [, $broken] = $project->trunk(['doctor']);
        [$syncCode, $syncOut] = $project->trunk(['package:sync']);
        [, $fixed] = $project->trunk(['doctor']);

        // Assert
        self::assertStringContainsString('trunk package:sync', $broken);
        self::assertSame(0, $syncCode);
        self::assertStringContainsString('Added integration module', $syncOut);
        self::assertStringNotContainsString('trunk package:sync', $fixed);
    }

    private function project(string $name, string $type): ScaffoldedProject
    {
        return $this->projects[] = new ScaffoldedProject($name, $type);
    }
}
