<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Environment;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Mvc\MvcModule;
use Trunk\Tests\Fixtures\Modules\MvcWebModule;
use Trunk\Tests\Support\FakeSapi;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\TuskModule;

/**
 * The mvc layer through the whole stack: a broken view never leaks in production, user input in a view
 * is escaped in every context the fixtures use, and redirects built from input cannot leave the site.
 */
final class MvcHardeningTest extends TestCase
{
    /** @var list<KernelHarness> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            new \Trunk\Support\Directory()->remove($harness->directory);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modes(): iterable
    {
        yield 'development container, source views' => ['development'];
        yield 'compiled container, compiled views' => ['compiled'];
    }

    #[DataProvider('modes')]
    public function test_a_broken_view_is_a_generic_500_in_production_without_paths_source_or_data(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode, Environment::Production);

        // Act
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/broken', ['Accept' => 'text/html']));
        $body = (string) $response->getBody();

        // Assert
        self::assertSame(500, $response->getStatusCode());
        foreach (['broken.tusk', 'nothing.here', 'db-password-1234', '/Fixtures/', 'tusk-template', 'TemplateRuntime', 'Undefined variable'] as $leak) {
            self::assertStringNotContainsString($leak, $body, $leak);
        }

        self::assertStringContainsString('req_', $body, 'the request id is how support finds the log line');
    }

    #[DataProvider('modes')]
    public function test_user_input_is_escaped_in_text_and_attribute_positions(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode, Environment::Production);
        $payloads = ['<script>alert(1)</script>', '"><img src=x onerror=alert(1)>', "' onmouseover='alert(1)", 'javascript:alert(1)', '<title><script>x', '&lt;b&gt;', "\xC3\x28<b>"];

        foreach ($payloads as $payload) {
            // Act
            $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/search/' . rawurlencode($payload)));
            $body = (string) $response->getBody();

            // Assert
            self::assertContains($response->getStatusCode(), [200, 404], $payload . ' (a path the router refuses, such as invalid UTF-8, is a 404)');

            if ($response->getStatusCode() === 404) {
                continue;
            }

            // The template has exactly two elements (h1 and a), so exactly four angle brackets of its own:
            // anything else in the output would be markup the input smuggled in.
            self::assertSame(4, substr_count($body, '<'), $payload);
            self::assertSame(4, substr_count($body, '>'), $payload . ' (raw > count)');
            self::assertSame(4, substr_count($body, '"'), $payload . ' (the two attributes keep their own quotes only)');
        }
    }

    #[DataProvider('modes')]
    public function test_a_redirect_built_from_input_can_only_stay_on_the_site(string $mode): void
    {
        // Arrange
        $kernel = $this->kernel($mode, Environment::Production);
        $attacks = ['//evil.example', 'https://evil.example', '%2F%2Fevil.example', '%5Cevil.example', 'x%0d%0aSet-Cookie:a=b', 'javascript:alert(1)', '%00', '..%2F..%2Fetc'];
        $outcomes = [];

        // Act
        foreach ($attacks as $target) {
            $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/open/' . $target));
            $outcomes[$target] = [$response->getStatusCode(), $response->getHeaderLine('Location'), $response->hasHeader('Set-Cookie')];
        }

        // Assert: the route parameter never contains a slash, so a local path needs one the controller adds
        foreach ($outcomes as $target => [$status, $location, $cookie]) {
            self::assertFalse($cookie, $target);
            self::assertNotContains($status, [301, 302, 303, 307, 308], $target . ' must not redirect: ' . $location);
        }
    }

    private function kernel(string $mode, Environment $environment): HttpKernel
    {
        $fixtures = __DIR__ . '/../Fixtures/views';
        $modules = [HttpModule::class, TuskModule::class, MvcModule::class, MvcWebModule::class];

        if ($mode === 'compiled') {
            $probe = $this->harnesses[] = new KernelHarness(new FakeSapi(), []);
            new TemplateBuilder()->build([$fixtures], $probe->directory . '/views-build');
            $harness = $this->harnesses[] = new KernelHarness(new FakeSapi(), $modules, ['views' => ['mode' => 'compiled', 'build' => $probe->directory . '/views-build']]);

            return $harness->compiled($environment);
        }

        $harness = $this->harnesses[] = new KernelHarness(new FakeSapi(), $modules, ['views' => ['mode' => 'development', 'paths' => [$fixtures], 'cache' => sys_get_temp_dir() . '/trunk-mvch-' . bin2hex(random_bytes(3))]]);

        return $harness->development($environment);
    }
}
