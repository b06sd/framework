<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Support\KernelHarness;

/**
 * With `cors.enabled` a preflight to any route is answered before routing ever runs (the router has
 * no way to match OPTIONS on a route only ever declared for GET), and a real cross-origin request
 * gets the allow-origin headers, including on error pages. Without it nothing changes: a preflight is
 * still whatever the router would have said (404/405), exactly as before this capability existed.
 */
final class CorsKernelTest extends TestCase
{
    private ?KernelHarness $harness = null;

    protected function tearDown(): void
    {
        $this->harness?->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development' => ['development'];
        yield 'compiled' => ['compiled'];
    }

    #[DataProvider('modes')]
    public function test_a_preflight_to_a_get_only_route_is_answered_without_reaching_the_router(string $mode): void
    {
        // Arrange
        $harness = $this->harness = new KernelHarness(modules: [HttpModule::class, WebModule::class], configuration: ['http' => ['cors' => ['enabled' => true, 'allowed_origins' => ['https://app.example.com']]]]);
        $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);

        // Act: /pages/{id} is only ever declared for GET, so without CORS this would be a 404 or 405.
        $preflight = $kernel->handle(new ServerRequest('OPTIONS', 'http://trunk.dev/pages/7', ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET']));

        // Assert
        self::assertSame(204, $preflight->getStatusCode(), $mode);
        self::assertSame('https://app.example.com', $preflight->getHeaderLine('Access-Control-Allow-Origin'), $mode);
        self::assertStringContainsString('GET', $preflight->getHeaderLine('Access-Control-Allow-Methods'), $mode);
    }

    #[DataProvider('modes')]
    public function test_a_real_cross_origin_request_gets_the_headers_including_on_a_404(string $mode): void
    {
        // Arrange
        $harness = $this->harness = new KernelHarness(modules: [HttpModule::class, WebModule::class], configuration: ['http' => ['cors' => ['enabled' => true, 'allowed_origins' => ['https://app.example.com']]]]);
        $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);

        // Act
        $ok = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/pages/7', ['Origin' => 'https://app.example.com']));
        $missing = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/nope', ['Origin' => 'https://app.example.com']));

        // Assert
        self::assertSame(200, $ok->getStatusCode(), $mode);
        self::assertSame('https://app.example.com', $ok->getHeaderLine('Access-Control-Allow-Origin'), $mode);
        self::assertSame(404, $missing->getStatusCode(), $mode);
        self::assertSame('https://app.example.com', $missing->getHeaderLine('Access-Control-Allow-Origin'), $mode . ': error pages carry it too');
    }

    #[DataProvider('modes')]
    public function test_nothing_changes_when_the_block_is_absent_or_disabled(string $mode): void
    {
        // Arrange
        foreach ([[], ['http' => ['cors' => ['enabled' => false]]]] as $configuration) {
            $harness = new KernelHarness(modules: [HttpModule::class, WebModule::class], configuration: $configuration);
            $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);

            // Act: exactly the preflight from the test above, but CORS never intercepts it.
            $preflight = $kernel->handle(new ServerRequest('OPTIONS', 'http://trunk.dev/pages/7', ['Origin' => 'https://app.example.com', 'Access-Control-Request-Method' => 'GET']));
            $ok = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/pages/7', ['Origin' => 'https://app.example.com']));

            // Assert
            self::assertNotSame(204, $preflight->getStatusCode(), $mode);
            self::assertFalse($preflight->hasHeader('Access-Control-Allow-Origin'), $mode);
            self::assertFalse($ok->hasHeader('Access-Control-Allow-Origin'), $mode);
            $harness->cleanUp();
        }
    }

    public function test_the_build_refuses_a_bad_cors_value_and_names_the_setting(): void
    {
        // Arrange
        $context = new BuildContext(new ModuleManifest([HttpModule::class]), new Runtime(Environment::Production, false, sys_get_temp_dir()), new Configuration(['http' => ['cors' => ['enabled' => true, 'allow_credentials' => true, 'allowed_origins' => ['*']]]]));

        // Act & Assert
        try {
            new HttpModule()->plan($context);
            self::fail('expected the build to fail');
        } catch (CompilationException $e) {
            self::assertStringContainsString('config/http.php:', $e->getMessage());
            self::assertStringContainsString('cannot be combined', $e->getMessage());
        }
    }
}
