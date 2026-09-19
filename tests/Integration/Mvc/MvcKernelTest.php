<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Mvc;

use PHPUnit\Framework\TestCase;
use Trunk\Http\HttpModule;
use Trunk\Http\Message\ServerRequest;
use Trunk\Mvc\MvcModule;
use Trunk\Tests\Fixtures\Modules\MvcWebModule;
use Trunk\Tests\Support\FakeSapi;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\TuskModule;

final class MvcKernelTest extends TestCase
{
    /** @var list<KernelHarness> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }
    }

    public function test_a_controller_renders_a_tusk_view_through_the_whole_stack_in_development_mode(): void
    {
        // Arrange
        $fixtures = __DIR__ . '/../../Fixtures/views';
        $cache = sys_get_temp_dir() . '/trunk-mvc-cache-' . bin2hex(random_bytes(3));
        $kernel = $this->harness(['mode' => 'development', 'paths' => [$fixtures], 'cache' => $cache])->development();

        // Act
        [$status, $type, $body] = $this->fetch($kernel, '/users');

        // Assert
        self::assertSame(200, $status);
        self::assertSame('text/html; charset=utf-8', $type);
        self::assertStringContainsString('<title>Team &lt;Core&gt;</title>', $body);
        self::assertStringContainsString('<article>&lt;i&gt;Bo&lt;/i&gt; (bo@trunk.dev)</article>', $body);
        self::assertStringContainsString('2 users', $body);
    }

    public function test_the_compiled_build_serves_identical_output_from_precompiled_views(): void
    {
        // Arrange
        $fixtures = __DIR__ . '/../../Fixtures/views';
        $cache = sys_get_temp_dir() . '/trunk-mvc-cache-' . bin2hex(random_bytes(3));
        $development = $this->harness(['mode' => 'development', 'paths' => [$fixtures], 'cache' => $cache])->development();
        $probe = $this->harness([]);
        new TemplateBuilder()->build([$fixtures], $probe->directory . '/views-build');
        $compiled = $this->harness(['mode' => 'compiled', 'build' => $probe->directory . '/views-build']);

        // Act
        $expected = $this->fetch($development, '/users');
        $actual = $this->fetch($compiled->compiled(), '/users');
        array_map(\unlink(...), glob($probe->directory . '/views-build/views/*') ?: []);
        array_map(\unlink(...), glob($probe->directory . '/views-build/*.php') ?: []);
        @rmdir($probe->directory . '/views-build/views');
        @rmdir($probe->directory . '/views-build');

        // Assert
        self::assertSame($expected, $actual);
    }

    public function test_json_and_redirect_responses_work_through_the_kernel(): void
    {
        // Arrange
        $kernel = $this->harness(['mode' => 'development', 'paths' => [], 'cache' => sys_get_temp_dir()])->development();

        // Act
        [$status, $type, $body] = $this->fetch($kernel, '/api/users/7');
        $redirect = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev/go'));

        // Assert
        self::assertSame([200, 'application/json', '{"id":7,"name":"Ada"}'], [$status, $type, $body]);
        self::assertSame(302, $redirect->getStatusCode());
        self::assertSame('/users', $redirect->getHeaderLine('Location'));
    }

    public function test_a_broken_template_becomes_a_generic_500_in_production(): void
    {
        // Arrange
        $kernel = $this->harness(['mode' => 'development', 'paths' => [sys_get_temp_dir() . '/trunk-no-such-views'], 'cache' => sys_get_temp_dir()])->development();

        // Act
        [$status, , $body] = $this->fetch($kernel, '/users');

        // Assert
        self::assertSame(500, $status);
        self::assertSame('Internal Server Error', $body);
    }

    /**
     * @param array<string, mixed> $views
     */
    private function harness(array $views): KernelHarness
    {
        return $this->harnesses[] = new KernelHarness(
            new FakeSapi(),
            [HttpModule::class, TuskModule::class, MvcModule::class, MvcWebModule::class],
            ['views' => $views],
        );
    }

    /**
     * @return array{int, string, string}
     */
    private function fetch(\Trunk\Http\Kernel\HttpKernel $kernel, string $path): array
    {
        $response = $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path));

        return [$response->getStatusCode(), $response->getHeaderLine('Content-Type'), (string) $response->getBody()];
    }
}
