<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Http\HttpModule;
use Trunk\Http\Message\ServerRequest;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Tests\Fixtures\Middleware\OrderAMiddleware;
use Trunk\Tests\Fixtures\Modules\RouteMiddlewareModule;
use Trunk\Tests\Support\KernelHarness;

final class RouteMiddlewareTest extends TestCase
{
    private KernelHarness $harness;

    protected function setUp(): void
    {
        $this->harness = new KernelHarness(modules: [HttpModule::class, RouteMiddlewareModule::class]);
    }

    protected function tearDown(): void
    {
        $this->harness->cleanUp();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'compiled' => ['compiled'];
        yield 'development' => ['development'];
    }

    #[DataProvider('modes')]
    public function test_group_middleware_applies_to_group_routes_only_and_route_middleware_nests_inside_it(string $mode): void
    {
        // Arrange & Act
        $open = $this->call($mode, '/open');
        $plain = $this->call($mode, '/api/plain');
        $extra = $this->call($mode, '/api/extra');
        $deep = $this->call($mode, '/api/deep/route', ['X-Pass' => '1']);

        // Assert
        self::assertSame([200, '', ''], $open);
        self::assertSame([200, 'A', ''], $plain);
        self::assertSame([200, 'B,A', ''], $extra, 'route middleware runs inside the group middleware: B finishes first');
        self::assertSame([200, 'B,A', ''], $deep);
    }

    #[DataProvider('modes')]
    public function test_route_middleware_can_short_circuit_and_the_controller_never_runs(string $mode): void
    {
        // Arrange & Act
        [$status, $order, $stopped] = $this->call($mode, '/api/deep/route');

        // Assert
        self::assertSame([401, '1'], [$status, $stopped]);
        self::assertSame('A', $order, 'the outer group middleware still sees the response on its way out; the inner route middleware (B) never ran');
    }

    public function test_the_route_table_records_middleware_in_group_then_route_order(): void
    {
        // Arrange
        $table = new RouteCompiler()->compileProviders([new RouteMiddlewareModule()]);

        // Act
        $byPattern = [];
        foreach ($table->routes as $route) {
            $byPattern[$route['pattern']] = array_map(static fn(string $m): string => substr(strrchr('\\' . $m, '\\') ?: $m, 1), $route['middleware']);
        }

        // Assert
        self::assertSame([], $byPattern['/open']);
        self::assertSame(['OrderAMiddleware'], $byPattern['/api/plain']);
        self::assertSame(['OrderAMiddleware', 'OrderBMiddleware'], $byPattern['/api/extra']);
        self::assertSame(['OrderAMiddleware', 'StopMiddleware', 'OrderBMiddleware'], $byPattern['/api/deep/route']);
    }

    public function test_an_unknown_or_wrong_type_of_middleware_fails_the_build_and_development_startup_with_the_route(): void
    {
        // Arrange
        $bad = new class implements \Trunk\Contracts\Module, \Trunk\Router\Definition\RouteProvider {
            public function register(\Trunk\Container\ContainerBuilder $builder): void {}

            public function boot(\Psr\Container\ContainerInterface $container): void {}

            public function routes(\Trunk\Router\Definition\RouteCollector $routes): void
            {
                $routes->get('/x', [\Trunk\Tests\Fixtures\Controllers\PageController::class, 'list'], middleware: [stdClass::class]);
            }
        };
        $table = new RouteCompiler()->compileProviders([$bad]);

        // Act
        $errors = new \Trunk\Http\Routing\RouteMiddlewareValidator()->errors($table);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('Route /x: stdClass must implement', $errors[0]);
    }

    public function test_invalid_middleware_names_are_rejected_when_the_route_is_defined(): void
    {
        // Arrange
        $collector = new \Trunk\Router\Definition\RouteCollector();
        $rejected = 0;

        // Act
        foreach (["evil'; DROP", 'has space', '../x', ''] as $name) {
            try {
                $collector->get('/y' . $rejected, [\Trunk\Tests\Fixtures\Controllers\PageController::class, 'list'], middleware: [$name]);
            } catch (\Trunk\Router\Exception\InvalidRouteException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
        self::assertSame(OrderAMiddleware::class, OrderAMiddleware::class);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{int, string, string}
     */
    private function call(string $mode, string $path, array $headers = []): array
    {
        $kernel = $mode === 'compiled' ? $this->harness->compiled() : $this->harness->development();
        $request = new ServerRequest('GET', 'http://trunk.dev' . $path);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $response = $kernel->handle($request);

        return [$response->getStatusCode(), $response->getHeaderLine('X-Order'), $response->getHeaderLine('X-Stopped')];
    }
}
