<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Modules\ScopeModule;
use Trunk\Tests\Support\FakeSapi;
use Trunk\Tests\Support\KernelHarness;

final class ScopeKernelTest extends TestCase
{
    private KernelHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new KernelHarness(new FakeSapi(), [HttpModule::class, LoggingModule::class, DiagnosticsModule::class, ScopeModule::class]);
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    public function test_scoped_services_are_per_request_and_singletons_are_shared_in_compiled_mode(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act
        $first = $this->probe($kernel);
        $second = $this->probe($kernel);

        // Assert
        self::assertSame('yes', $first['same']);
        self::assertSame('/probe', $first['path']);
        self::assertNotSame($first['scoped'], $second['scoped']);
        self::assertSame($first['singleton'], $second['singleton']);
    }

    public function test_development_mode_has_the_same_scope_semantics(): void
    {
        // Arrange
        $kernel = new KernelHarness(new FakeSapi(), [HttpModule::class, LoggingModule::class, DiagnosticsModule::class, ScopeModule::class])->development();

        // Act
        $first = $this->probe($kernel);
        $second = $this->probe($kernel);

        // Assert
        self::assertSame('yes', $first['same']);
        self::assertNotSame($first['scoped'], $second['scoped']);
        self::assertSame($first['singleton'], $second['singleton']);
    }

    public function test_the_default_logger_binding_lets_the_kernel_start_and_errors_stay_generic(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/missing'));

        // Assert
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', (string) $response->getBody());
    }

    /**
     * @return array<string, string>
     */
    private function probe(HttpKernel $kernel, string $path = '/probe'): array
    {
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path));
        self::assertSame(200, $response->getStatusCode());
        $parts = [];

        foreach (explode(';', (string) $response->getBody()) as $pair) {
            [$key, $value] = explode('=', $pair, 2) + [1 => ''];
            $parts[$key] = $value;
        }

        return $parts;
    }
}
