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
 * With `security_headers.enabled` the kernel puts the policy on every response: pages, 404s, route
 * failures and the generic 500, in development and compiled containers. Without it nothing changes.
 */
final class SecurityHeadersKernelTest extends TestCase
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
    public function test_every_kind_of_response_carries_the_policy_when_enabled(string $mode): void
    {
        // Arrange
        $harness = $this->harness = new KernelHarness(modules: [HttpModule::class, WebModule::class], configuration: ['http' => ['security_headers' => ['enabled' => true]]]);
        $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);

        // Act
        $paths = ['/pages/7' => 200, '/missing' => 404, '/boom' => 500];
        $seen = [];

        foreach ($paths as $path => $status) {
            $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path));
            $seen[$path] = [$response->getStatusCode(), $response->getHeaderLine('X-Frame-Options'), $response->getHeaderLine('Referrer-Policy'), $response->getHeaderLine('X-Content-Type-Options'), $response->hasHeader('Strict-Transport-Security')];
        }

        $secure = $kernel->handle(new ServerRequest('GET', 'https://trunk.dev/pages/7'));

        // Assert
        foreach ($paths as $path => $status) {
            self::assertSame([$status, 'DENY', 'strict-origin-when-cross-origin', 'nosniff', false], $seen[$path], $path);
        }

        self::assertTrue($secure->hasHeader('Strict-Transport-Security'));
    }

    #[DataProvider('modes')]
    public function test_nothing_changes_when_the_block_is_absent_or_disabled(string $mode): void
    {
        // Arrange
        foreach ([[], ['http' => ['security_headers' => ['enabled' => false]]]] as $configuration) {
            $harness = new KernelHarness(modules: [HttpModule::class, WebModule::class], configuration: $configuration);
            $kernel = $mode === 'compiled' ? $harness->compiled(Environment::Production) : $harness->development(Environment::Production);

            // Act
            $response = $kernel->handle(new ServerRequest('GET', 'https://trunk.dev/pages/7'));

            // Assert
            self::assertFalse($response->hasHeader('X-Frame-Options'));
            self::assertFalse($response->hasHeader('Content-Security-Policy'));
            self::assertFalse($response->hasHeader('Strict-Transport-Security'));
            $harness->cleanUp();
        }
    }

    public function test_the_build_refuses_a_bad_security_header_value_and_names_the_setting(): void
    {
        // Arrange
        $context = new BuildContext(new ModuleManifest([HttpModule::class]), new Runtime(Environment::Production, false, sys_get_temp_dir()), new Configuration(['http' => ['security_headers' => ['enabled' => true, 'referrer_policy' => "x\r\ny"]]]));

        // Act & Assert
        try {
            new HttpModule()->plan($context);
            self::fail('expected the build to fail');
        } catch (CompilationException $e) {
            self::assertStringContainsString('config/http.php:', $e->getMessage());
            self::assertStringContainsString('printable ASCII', $e->getMessage());
        }
    }
}
