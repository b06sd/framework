<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Http\Build\HttpArtifactBuilder;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Modules\UnboundControllerModule;
use Trunk\Tests\Support\KernelHarness;

final class KernelTest extends TestCase
{
    private KernelHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new KernelHarness();
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    public function test_the_compiled_kernel_serves_routes_with_bound_arguments_and_global_middleware(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act & Assert
        self::assertSame([200, 'page 7 via GET', '1', ''], $this->call($kernel, 'GET', '/pages/7'));
        self::assertSame([200, 'list 1', '1', ''], $this->call($kernel, 'GET', '/list'));
        self::assertSame([200, 'list 3', '1', ''], $this->call($kernel, 'GET', '/list/3'));
        self::assertSame([200, 'on', '1', ''], $this->call($kernel, 'GET', '/flag/true'));
        self::assertSame([200, 'a b/c', '1', ''], $this->call($kernel, 'GET', '/echo/a%20b%2Fc'));
    }

    public function test_error_responses_still_pass_through_global_middleware(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act & Assert
        self::assertSame([404, 'Not Found', '1', ''], $this->call($kernel, 'GET', '/missing'));
        self::assertSame([404, 'Not Found', '1', ''], $this->call($kernel, 'GET', '/pages/abc'));
        self::assertSame([405, 'Method Not Allowed', '1', 'GET, HEAD'], $this->call($kernel, 'POST', '/pages/7'));
        self::assertSame([500, 'Internal Server Error', '1', ''], $this->call($kernel, 'GET', '/bad'));
        self::assertSame([500, 'Internal Server Error', '1', ''], $this->call($kernel, 'GET', '/boom'));
    }

    public function test_middleware_can_short_circuit_before_routing(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act
        $result = $this->call($kernel, 'GET', '/blocked');

        // Assert
        self::assertSame([403, '', '1', ''], $result);
    }

    public function test_development_mode_behaves_exactly_like_compiled_mode(): void
    {
        // Arrange
        $compiled = $this->harness->compiled();
        $development = new KernelHarness()->development();
        $matrix = [['GET', '/pages/7'], ['GET', '/list'], ['GET', '/list/3'], ['GET', '/flag/false'], ['POST', '/pages/7'], ['GET', '/missing'], ['GET', '/blocked'], ['GET', '/boom'], ['HEAD', '/pages/9']];

        // Act & Assert
        foreach ($matrix as [$method, $path]) {
            self::assertSame($this->call($compiled, $method, $path), $this->call($development, $method, $path), $method . ' ' . $path);
        }
    }

    public function test_send_emits_the_exact_response_through_the_sapi(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act
        $kernel->send(new ServerRequest('GET', 'http://trunk.dev/pages/7'));

        // Assert
        $calls = $this->harness->sapi->calls;
        self::assertMatchesRegularExpression('/^header:X-Request-Id: req_[0-9A-Z]{26}$/D', $calls[3] ?? '');
        unset($calls[3]);
        self::assertSame([
            'status:HTTP/1.1 200 OK',
            'header:Content-Type: text/plain',
            'header:X-Test: 1',
            'write:page 7 via GET',
        ], array_values($calls));
    }

    public function test_the_builder_reports_an_unresolvable_dependency_with_a_path_and_a_fix_and_writes_nothing(): void
    {
        // Arrange
        $manifest = new ModuleManifest([UnboundControllerModule::class]);
        $directory = $this->harness->directory;

        // Act
        try {
            new HttpArtifactBuilder()->build($manifest, $directory);
            self::fail('Expected a CompilationException.');
        } catch (CompilationException $e) {
            // Assert
            self::assertCount(1, $e->errors);
            self::assertStringContainsString('Cannot resolve "Psr\\Http\\Message\\ResponseFactoryInterface"', $e->errors[0]);
            self::assertStringContainsString('PageController', $e->errors[0]);
            self::assertStringContainsString('Fix:', $e->errors[0]);
            self::assertDirectoryDoesNotExist($directory);
        }
    }

    /**
     * @return array{int, string, string, string}
     */
    private function call(HttpKernel $kernel, string $method, string $path): array
    {
        $response = $kernel->handle(new ServerRequest($method, 'http://trunk.dev' . $path));

        return [$response->getStatusCode(), (string) $response->getBody(), $response->getHeaderLine('X-Test'), $response->getHeaderLine('Allow')];
    }
}
