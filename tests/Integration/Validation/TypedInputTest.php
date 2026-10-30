<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Validation;

use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Stream\Stream;
use Trunk\Support\Directory;
use Trunk\Tests\Fixtures\Modules\MisboundInputModule;
use Trunk\Tests\Fixtures\Modules\ValidationWebModule;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Validation\ValidationModule;

/**
 * `#[Body]` and `#[Query]` handler parameters, end to end, in development and from a real build.
 */
final class TypedInputTest extends TestCase
{
    /** @var list<KernelHarness> */
    private array $harnesses = [];

    private string $build;

    protected function setUp(): void
    {
        $this->build = sys_get_temp_dir() . '/trunk-typed-input-' . bin2hex(random_bytes(4));
        mkdir($this->build);
    }

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }

        new Directory()->remove($this->build);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function modes(): iterable
    {
        yield 'development' => [false];
        yield 'compiled' => [true];
    }

    #[DataProvider('modes')]
    public function test_a_body_parameter_arrives_validated_next_to_route_parameters(bool $compiled): void
    {
        // Arrange: Order is not listed in validation.requests, so the build must find it through #[Body]
        $kernel = $this->kernel($compiled);

        // Act
        $response = $kernel->handle($this->json('POST', '/shops/7/orders', ['sku' => 'TEA-1', 'quantity' => 3]));

        // Assert
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"shop":7,"sku":"TEA-1","quantity":3}', (string) $response->getBody());
    }

    #[DataProvider('modes')]
    public function test_an_invalid_body_is_a_422_and_the_controller_never_runs(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        $response = $kernel->handle($this->json('POST', '/shops/7/orders', ['sku' => '', 'quantity' => 500]));
        $json = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        // Assert
        self::assertSame(422, $response->getStatusCode());
        self::assertIsArray($json);
        self::assertIsArray($json['error'] ?? null);
        self::assertIsArray($json['error']['details'] ?? null);
        self::assertSame(['sku' => 'required', 'quantity' => 'max'], $json['error']['details']['rules'] ?? null);
    }

    #[DataProvider('modes')]
    public function test_a_query_parameter_reads_the_query_string_strictly(bool $compiled): void
    {
        // Arrange
        $kernel = $this->kernel($compiled);

        // Act
        $ok = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/typed/search?page=2&q=tea')->withQueryParams(['page' => '2', 'q' => 'tea']));
        $bad = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/typed/search?page=2x', ['Accept' => 'application/json'])->withQueryParams(['page' => '2x']));

        // Assert
        self::assertSame('{"page":2,"q":"tea"}', (string) $ok->getBody());
        self::assertSame(422, $bad->getStatusCode());
    }

    public function test_a_body_class_marked_json_only_still_refuses_a_form(): void
    {
        // Arrange
        $kernel = $this->kernel(false);
        $form = new ServerRequest('PUT', 'http://trunk.dev/typed/rename', ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'])->withParsedBody(['name' => 'Ada']);

        // Act
        $response = $kernel->handle($form);

        // Assert
        self::assertSame(415, $response->getStatusCode());
    }

    public function test_typed_inputs_without_the_validation_capability_fail_at_startup_with_the_fix(): void
    {
        // Arrange
        $harness = new KernelHarness(modules: [HttpModule::class, ValidationWebModule::class]);
        $this->harnesses[] = $harness;

        // Act & Assert
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Route /shops/{shop:int}/orders: parameter $order is #[Body], which needs the validation capability. Fix: trunk package:install validation');
        $harness->development();
    }

    public function test_the_build_refuses_a_query_parameter_whose_class_is_read_from_json(): void
    {
        // Arrange
        $context = new BuildContext(new ModuleManifest([HttpModule::class, ValidationModule::class, MisboundInputModule::class]), new Runtime(Environment::Production, false, $this->build), new Configuration([]));

        // Act & Assert
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('JsonOnly is #[From(Source::Json)] but parameter $rename is #[Query]');
        new ValidationModule()->plan($context);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(string $method, string $path, array $data): ServerRequest
    {
        return new ServerRequest($method, 'http://trunk.dev' . $path, ['Content-Type' => 'application/json', 'Accept' => 'application/json'], Stream::fromString(json_encode($data, \JSON_THROW_ON_ERROR)));
    }

    private function kernel(bool $compiled): HttpKernel
    {
        $harness = new KernelHarness(modules: [HttpModule::class, ValidationModule::class, ValidationWebModule::class], configuration: ['validation' => ['mode' => $compiled ? 'compiled' : 'development', 'build' => $this->build]]);
        $this->harnesses[] = $harness;

        if (!$compiled) {
            return $harness->development();
        }

        $context = new BuildContext($harness->manifest(), new Runtime(Environment::Production, false, $this->build), new Configuration(['validation' => ['requests' => []]]));
        array_map(fn(Closure $write) => $write($this->build), new ValidationModule()->plan($context)->writers);
        self::assertStringContainsString('Order', (string) file_get_contents($this->build . '/validation.php'));

        return $harness->compiled();
    }
}
