<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Environment;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Support\ForbiddenConstructScanner;
use Trunk\Tests\Support\KernelHarness;

final class HttpKernelSecurityTest extends TestCase
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

    public function test_production_never_leaks_exception_details_even_when_debug_is_requested(): void
    {
        // Arrange
        $kernel = $this->harness->compiled(Environment::Production, true);

        // Act
        $body = (string) $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/boom'))->getBody();

        // Assert
        self::assertSame('Internal Server Error', $body);
        self::assertStringNotContainsString('secret database password', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
    }

    public function test_details_appear_only_outside_production_with_debug_on(): void
    {
        // Arrange
        $local = new KernelHarness()->development(Environment::Local, true);
        $quiet = new KernelHarness()->development(Environment::Local, false);

        // Act
        $verbose = (string) $local->handle(new ServerRequest('GET', 'http://trunk.dev/boom'))->getBody();
        $terse = (string) $quiet->handle(new ServerRequest('GET', 'http://trunk.dev/boom'))->getBody();

        // Assert
        self::assertStringContainsString('secret database password', $verbose);
        self::assertSame('Internal Server Error', $terse);
    }

    public function test_hostile_route_parameters_never_reach_a_controller(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();
        $paths = ['/echo/a%00b', '/echo/%FF%FE', '/pages/99999999999999999999', '/flag/maybe', '/pages/-', '/pages/1e5'];

        // Act
        $statuses = array_map(static fn(string $path): int => $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path))->getStatusCode(), $paths);

        // Assert
        self::assertSame([404, 404, 404, 404, 404, 404], $statuses);
    }

    public function test_error_responses_are_plain_text_and_not_sniffable(): void
    {
        // Arrange
        $kernel = $this->harness->compiled();

        // Act
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/missing'));

        // Assert
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function test_generated_build_artifacts_contain_no_dangerous_constructs(): void
    {
        // Arrange
        $this->harness->compiled();
        $scanner = new ForbiddenConstructScanner();
        $violations = [];

        // Act
        foreach (['container.php', 'routes.php', 'pipeline.php', 'modules.php'] as $file) {
            $violations = [...$violations, ...$scanner->scan((string) file_get_contents($this->harness->directory . '/' . $file), $file)];
        }

        // Assert
        self::assertSame([], $violations);
    }
}
