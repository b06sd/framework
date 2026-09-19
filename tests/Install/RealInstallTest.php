<?php

declare(strict_types=1);

namespace Trunk\Tests\Install;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\Cli;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * What a new user gets: `trunk new`, a real `composer install` (no borrowed autoloader, no dev
 * dependencies), then the project's own vendor/bin/trunk and public/index.php. This is the only
 * test that can prove a project's Composer requirements are complete, so it must stay real.
 */
final class RealInstallTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function setUp(): void
    {
        [$code] = new Cli()->run(['composer', '--version'], sys_get_temp_dir());

        if ($code !== 0) {
            self::markTestSkipped('composer is not available.');
        }
    }

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function profiles(): iterable
    {
        yield 'api' => ['api', '/api/customers/2', '"name":"Grace Hopper"'];
        yield 'web' => ['web', '/', '<h1>'];
        yield 'self-contained' => ['self-contained', '/api/customers/1', '"id":1'];
        yield 'cli' => ['cli', '', ''];
        yield 'worker' => ['worker', '', ''];
    }

    #[DataProvider('profiles')]
    public function test_a_freshly_installed_project_passes_doctor_builds_and_runs_in_production(string $type, string $uri, string $expected): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('fresh-' . $type, $type, realInstall: true);

        // Act
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [$listCode, $listOut] = $project->trunk(['list'], null, ['APP_ENV' => 'production']);
        [, $body, $err] = $uri === '' ? [0, '', ''] : $project->request('GET', $uri, 'production');

        // Assert
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertStringContainsString('✓ Capability dependencies', $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame(0, $listCode, $listOut);
        self::assertStringNotContainsString('not found', $err . $body, 'a missing class means the project lacks a Composer requirement');
        self::assertStringContainsString($expected, $body, $err);
    }

    public function test_auth_installs_migrates_issues_a_token_and_guards_a_route_in_a_real_project(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('authed', 'api', realInstall: true);
        $dir = $project->directory;

        // Act
        $project->trunk(['package:install', 'console']);
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'auth']);
        [$tableCode] = $project->trunk(['auth:table', '--users']);
        [$migrateCode, $migrateOut, $migrateErr] = $project->trunk(['migrate']);
        self::assertSame([0, 0, 0], [$installCode, $tableCode, $migrateCode], $installOut . $installErr . $migrateOut . $migrateErr);
        $pdo = new PDO('sqlite:' . $dir . '/storage/database.sqlite');
        $pdo->prepare('INSERT INTO users (name, email, password, session_version) VALUES (?, ?, ?, ?)')->execute(['Ada', 'ada@example.com', password_hash('correct horse battery', \PASSWORD_ARGON2ID), '1']);
        [$tokenCode, $tokenOut] = $project->trunk(['auth:token', '1', 'ci', '--abilities=read']);
        preg_match('/trk_[A-Za-z0-9_-]{16}\.[A-Za-z0-9_-]{43}/', $tokenOut, $m);
        $token = $m[0] ?? '';
        file_put_contents($dir . '/app/Controllers/MeController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Auth\\Auth;\nuse Trunk\\Http\\Response\\ResponseBuilder;\n\nfinal readonly class MeController\n{\n    public function __construct(private ResponseBuilder \$responses, private Auth \$auth) {}\n\n    public function show(): ResponseInterface\n    {\n        return \$this->responses->json(['id' => \$this->auth->user()?->authId(), 'read' => \$this->auth->tokenCan('read'), 'write' => \$this->auth->tokenCan('write')]);\n    }\n}\n");
        $routes = (string) file_get_contents($dir . '/routes/api.php');
        file_put_contents($dir . '/routes/api.php', str_replace("    });\n};", "        \$api->get('/me', [\\App\\Controllers\\MeController::class, 'show'], middleware: [\\Trunk\\Auth\\Http\\RequireToken::class]);\n    });\n};", $routes));
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        [, $ok] = $project->request('GET', '/api/me', 'production', authorization: 'Bearer ' . $token);
        [, $missing] = $project->request('GET', '/api/me', 'production');
        [, $forged] = $project->request('GET', '/api/me', 'production', authorization: 'Bearer ' . substr($token, 0, -1) . 'x');
        [$pruneCode, $pruneOut] = $project->trunk(['auth:prune']);

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertSame([0, 0, 0], [$tableCode, $migrateCode, $tokenCode], $migrateOut . $tokenOut);
        self::assertNotSame('', $token, $tokenOut);
        self::assertSame(0, $doctorCode, $doctorOut);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertSame('{"id":"1","read":true,"write":false}', $ok);
        self::assertStringContainsString('Unauthorized', $missing);
        self::assertStringContainsString('Unauthorized', $forged);
        self::assertSame(0, $pruneCode, $pruneOut);
    }

    public function test_enabling_a_capability_installs_what_it_needs(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('grows', 'cli', realInstall: true);

        // Act
        [$code, $out, $err] = $project->trunk(['package:install', 'cache']);
        $composer = json_decode((string) file_get_contents($project->directory . '/composer.json'), true, 16, \JSON_THROW_ON_ERROR);
        [$doctorCode, $doctorOut] = $project->trunk(['doctor']);

        // Assert
        self::assertSame(0, $code, $out . $err);
        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        self::assertArrayHasKey('psr/simple-cache', $composer['require']);
        self::assertFileExists($project->directory . '/vendor/psr/simple-cache');
        self::assertSame(0, $doctorCode, $doctorOut);
    }
}
