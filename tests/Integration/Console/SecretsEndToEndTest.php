<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * A secret that exists only in the process environment must never reach a build artifact, must
 * still be usable by the running application, and the build must not be world-readable.
 */
final class SecretsEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_secrets_are_resolved_at_runtime_and_never_written_to_the_build(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('vault', 'api');
        $secret = 'tok_' . bin2hex(random_bytes(8));
        file_put_contents($project->directory . '/config/vault.php', "<?php\n\ndeclare(strict_types=1);\n\nuse Trunk\\Foundation\\Runtime;\n\nreturn static fn(Runtime \$runtime): array => ['token' => \$runtime->secret('APP_TOKEN'), 'plain' => \$runtime->variable('APP_PLAIN', 'x')];\n");
        file_put_contents($project->directory . '/app/Controllers/VaultController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Foundation\\Configuration;\nuse Trunk\\Http\\Response\\ResponseBuilder;\n\nfinal readonly class VaultController\n{\n    public function __construct(private Configuration \$config, private ResponseBuilder \$responses) {}\n\n    public function show(): ResponseInterface\n    {\n        return \$this->responses->text(\$this->config->string('vault.token'));\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/api.php');
        file_put_contents($project->directory . '/routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->get('/vault', [\\App\\Controllers\\VaultController::class, 'show']);\n        \$api->get('/customers', ", $routes));

        // Act
        [$buildCode, $buildOut] = $project->trunk(['build'], null, ['APP_TOKEN' => $secret, 'APP_PLAIN' => 'plain-value']);
        $leaks = [];
        $modes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project->directory . '/build', RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $modes[$item->isDir() ? 'dir' : 'file'][substr(\sprintf('%o', $item->getPerms()), -4)] = true;

            if ($item->isFile() && str_contains((string) file_get_contents($item->getPathname()), $secret)) {
                $leaks[] = $item->getFilename();
            }
        }
        $config = (string) file_get_contents($project->directory . '/build/config.php');
        [, $withSecret] = $project->request('GET', '/api/vault', 'production');
        $viaEnv = new \Trunk\Tests\Support\Cli()->run([\PHP_BINARY, \dirname($project->directory) . '/request.php', $project->directory, 'GET', '/api/vault'], $project->directory, ['APP_ENV' => 'production', 'APP_TOKEN' => $secret]);
        $without = $project->request('GET', '/api/vault', 'production');

        // Assert
        self::assertSame(0, $buildCode, $buildOut);
        self::assertSame([], $leaks, 'the secret value is in no build artifact');
        self::assertStringContainsString("'APP_TOKEN'", $config, 'the reference is compiled');
        self::assertStringContainsString('plain-value', $config, 'ordinary variables are still compiled');
        self::assertSame(['0640'], array_keys($modes['file'] ?? []));
        self::assertSame('0750', substr(\sprintf('%o', fileperms($project->directory . '/build')), -4));
        self::assertSame([], array_diff(array_keys($modes['dir'] ?? []), ['0750']));
        self::assertSame($secret, $viaEnv[1], 'the running application resolves it from the real environment');
        self::assertStringNotContainsString($secret, $withSecret . $without[1]);
        self::assertSame('Internal Server Error', $without[1], 'without the variable the request fails closed with a generic error');
    }
}
