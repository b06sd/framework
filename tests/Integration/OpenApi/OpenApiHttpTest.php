<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\OpenApi;

use Closure;
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
use Trunk\OpenApi\Exception\OpenApiException;
use Trunk\OpenApi\Http\OpenApiController;
use Trunk\OpenApi\OpenApi;
use Trunk\OpenApi\OpenApiModule;
use Trunk\Tests\Fixtures\OpenApi\BrokenOpenApiModule;
use Trunk\Tests\Fixtures\OpenApi\OpenApiRoutesModule;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Validation\ValidationModule;

/**
 * GET /openapi.json and GET /docs, from the routes in development and from build/openapi.json in production.
 */
final class OpenApiHttpTest extends TestCase
{
    private const array MODULES = [HttpModule::class, ValidationModule::class, OpenApiModule::class, OpenApiRoutesModule::class];

    /** @var list<KernelHarness> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }
    }

    public function test_in_development_the_document_is_generated_from_the_current_routes(): void
    {
        // Arrange
        $kernel = $this->development(serve: true);

        // Act
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/openapi.json'));
        $document = json_decode((string) $response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertIsArray($document);
        self::assertIsArray($document['paths'] ?? null);
        self::assertArrayHasKey('/orders/{id}', $document['paths']);
        self::assertArrayNotHasKey('/docs', $document['paths']);
    }

    public function test_the_docs_page_loads_pinned_swagger_ui_under_a_strict_policy(): void
    {
        // Arrange
        $kernel = $this->development(serve: true);

        // Act
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/docs'));
        $html = (string) $response->getBody();
        $policy = $response->getHeaderLine('Content-Security-Policy');

        // Assert
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('<title>Shop</title>', $html);
        self::assertStringContainsString('swagger-ui-dist@5.33.3/swagger-ui-bundle.js" integrity="sha384-', $html);
        self::assertStringContainsString("url: 'openapi.json'", $html);
        self::assertSame(OpenApiController::policy(), $policy);
        self::assertStringStartsWith("default-src 'none'; script-src https://cdn.jsdelivr.net 'sha256-", $policy);
        self::assertStringContainsString("connect-src 'self'", $policy);
        self::assertStringNotContainsString("'unsafe-eval'", $policy);
    }

    public function test_the_inline_script_hash_in_the_policy_matches_the_script_on_the_page(): void
    {
        // Arrange
        $kernel = $this->development(serve: true);

        // Act
        $html = (string) $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/docs'))->getBody();
        preg_match('#<script>(.*?)</script>#s', $html, $script);

        // Assert
        self::assertStringContainsString("'sha256-" . base64_encode(hash('sha256', $script[1] ?? '', true)) . "'", OpenApiController::policy());
    }

    public function test_both_routes_are_404_unless_serving_is_on(): void
    {
        // Arrange
        $kernel = $this->development(serve: false);

        // Act
        $spec = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/openapi.json', ['Accept' => 'application/json']));
        $docs = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/docs', ['Accept' => 'application/json']));

        // Assert
        self::assertSame(404, $spec->getStatusCode());
        self::assertSame(404, $docs->getStatusCode());
        self::assertStringNotContainsString('orders', (string) $spec->getBody());
    }

    public function test_the_build_writes_the_document_and_production_serves_that_file(): void
    {
        // Arrange
        $harness = $this->harness(serve: true, mode: 'compiled');
        $context = new BuildContext($harness->manifest(), new Runtime(Environment::Production, false, $harness->directory), $this->configuration($harness, true, 'compiled'));
        array_map(static fn(Closure $write) => $write($harness->directory), new OpenApiModule()->plan($context)->writers);
        $built = (string) file_get_contents($harness->directory . '/openapi.json');
        unlink($harness->directory . '/trunk.php');   // production never reads the routes again

        // Act
        $served = (string) $harness->compiled()->handle(new ServerRequest('GET', 'http://trunk.dev/openapi.json'))->getBody();

        // Assert
        self::assertSame(json_decode($built, true), json_decode($served, true));
        self::assertStringContainsString('"/shops/{shop}/orders"', $built);
    }

    public function test_a_broken_returns_attribute_fails_the_build(): void
    {
        // Arrange
        $harness = $this->harness(serve: false, mode: 'compiled');
        $context = new BuildContext(new ModuleManifest([...self::MODULES, BrokenOpenApiModule::class]), new Runtime(Environment::Production, false, $harness->directory), $this->configuration($harness, false, 'compiled'));

        // Act & Assert
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('OpenAPI: Route /broken:');
        new OpenApiModule()->plan($context);
    }

    public function test_production_without_a_build_says_how_to_fix_it(): void
    {
        // Arrange
        $harness = $this->harness(serve: true, mode: 'compiled');
        $openApi = new OpenApi(new Runtime(Environment::Production, false, $harness->directory), $this->configuration($harness, true, 'compiled'));

        // Act & Assert
        $this->expectException(OpenApiException::class);
        $this->expectExceptionMessage('openapi.json is missing: run `trunk build`.');
        $openApi->json();
    }

    private function development(bool $serve): HttpKernel
    {
        return $this->harness($serve, 'development')->development(Environment::Local);
    }

    /**
     * A project directory with a trunk.php listing the modules, as development mode reads it.
     */
    private function harness(bool $serve, string $mode): KernelHarness
    {
        $directory = sys_get_temp_dir() . '/trunk-openapi-' . bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory . '/trunk.php', '<?php return ' . var_export(['name' => 'shop', 'type' => 'api', 'modules' => self::MODULES], true) . ';');
        $harness = new KernelHarness(modules: self::MODULES, configuration: ['openapi' => self::settings($directory, $serve, $mode)]);
        $harness->directory = $directory;
        $this->harnesses[] = $harness;

        return $harness;
    }

    private function configuration(KernelHarness $harness, bool $serve, string $mode): Configuration
    {
        return new Configuration(['openapi' => self::settings($harness->directory, $serve, $mode)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings(string $directory, bool $serve, string $mode): array
    {
        return ['title' => 'Shop', 'version' => '1.0.0', 'serve' => $serve, 'mode' => $mode, 'build' => $directory];
    }
}
