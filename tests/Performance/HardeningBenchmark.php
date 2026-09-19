<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Environment;
use Trunk\Http\HttpModule;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Mvc\MvcModule;
use Trunk\Tests\Fixtures\Modules\MvcWebModule;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Fixtures\Orm\Customer;
use Trunk\Tests\Support\FakeSapi;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Tests\Support\OrmHarness;
use Trunk\Tusk\Compiler\TemplateBuilder;
use Trunk\Tusk\TuskModule;

/**
 * Numbers for the http, mvc and orm hardening pass (stderr; loose assertions). Read them next to
 * docs/HARDENING_REPORT.md.
 */
final class HardeningBenchmark extends TestCase
{
    public function test_http_request_paths_and_the_cost_of_the_security_headers(): void
    {
        // Arrange
        $plain = new KernelHarness(new FakeSapi(), [HttpModule::class, WebModule::class]);
        $secured = new KernelHarness(new FakeSapi(), [HttpModule::class, WebModule::class], ['http' => ['security_headers' => ['enabled' => true]]]);
        $kernelPlain = $plain->compiled(Environment::Production);
        $kernelSecured = $secured->compiled(Environment::Production);
        $creator = new ServerRequestCreator();
        $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/pages/7?x=1', 'HTTP_HOST' => 'trunk.dev', 'SERVER_PROTOCOL' => 'HTTP/2.0', 'HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.5', 'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9', 'HTTP_ACCEPT_ENCODING' => 'gzip, deflate, br', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/153 Safari/537.36', 'HTTP_COOKIE' => 'a=b', 'HTTP_CONNECTION' => 'keep-alive', 'HTTP_CACHE_CONTROL' => 'max-age=0', 'HTTP_UPGRADE_INSECURE_REQUESTS' => '1', 'HTTP_SEC_FETCH_SITE' => 'none'];

        // Act
        $create = $this->sample(static fn() => $creator->fromArrays($server, ['x' => '1'], null, ['a' => 'b']), 20000);
        $without = $this->sample(static fn() => $kernelPlain->handle(new ServerRequest('GET', 'http://trunk.dev/pages/7')), 10000);
        $with = $this->sample(static fn() => $kernelSecured->handle(new ServerRequest('GET', 'http://trunk.dev/pages/7')), 10000);
        $notFound = $this->sample(static fn() => $kernelSecured->handle(new ServerRequest('GET', 'http://trunk.dev/missing')), 5000);

        // Assert (report)
        $this->line('http', 'ServerRequestCreator (14 browser headers)', $create);
        $this->line('http', 'request, security headers off', $without);
        $this->line('http', 'request, security headers on', $with);
        $this->line('http', '404 with security headers', $notFound);
        self::assertNotEmpty($with);
        $plain->cleanUp();
        $secured->cleanUp();
    }

    public function test_mvc_view_rendering_through_the_whole_stack(): void
    {
        // Arrange
        $fixtures = __DIR__ . '/../Fixtures/views';
        $development = new KernelHarness(new FakeSapi(), [HttpModule::class, TuskModule::class, MvcModule::class, MvcWebModule::class], ['views' => ['mode' => 'development', 'paths' => [$fixtures], 'cache' => sys_get_temp_dir() . '/trunk-hb-' . bin2hex(random_bytes(3))]]);
        $probe = new KernelHarness(new FakeSapi(), []);
        new TemplateBuilder()->build([$fixtures], $probe->directory . '/views-build');
        $compiled = new KernelHarness(new FakeSapi(), [HttpModule::class, TuskModule::class, MvcModule::class, MvcWebModule::class], ['views' => ['mode' => 'compiled', 'build' => $probe->directory . '/views-build']]);
        $devKernel = $development->development(Environment::Production);
        $compiledKernel = $compiled->compiled(Environment::Production);

        // Act
        $dev = $this->sample(static fn() => $devKernel->handle(new ServerRequest('GET', 'http://trunk.dev/users')), 5000);
        $built = $this->sample(static fn() => $compiledKernel->handle(new ServerRequest('GET', 'http://trunk.dev/users')), 5000);
        $json = $this->sample(static fn() => $compiledKernel->handle(new ServerRequest('GET', 'http://trunk.dev/api/users/7')), 5000);

        // Assert (report)
        $this->line('mvc', 'Tusk view + layout (development, cached compile)', $dev);
        $this->line('mvc', 'Tusk view + layout (compiled build)', $built);
        $this->line('mvc', 'JSON response through Responder', $json);
        self::assertNotEmpty($built);
    }

    public function test_orm_hot_paths_including_paging_and_streaming(): void
    {
        // Arrange
        $orm = new OrmHarness();
        $manager = $orm->manager();

        for ($i = 0; $i < 2000; ++$i) {
            $manager->persist(new Customer(name: 'C' . $i, email: 'c' . $i . '@example.com'));
        }

        $manager->flush();
        $manager->clear();
        $repository = $manager->repository(Customer::class);

        // Act
        $find = $this->sample(function () use ($orm): void {
            $orm->manager()->repository(Customer::class)->find(7);
        }, 3000);
        $first = $this->sample(static fn() => $repository->query()->readOnly()->where('email', 'c7@example.com')->first(), 3000);
        $page = $this->sample(static fn() => $repository->query()->readOnly()->sortBy('name')->paginate(3, 20), 1000);
        $filter = $this->sample(static fn() => $repository->query()->readOnly()->filter(['status' => 'active', 'name' => ['C1', 'C2', 'C3']])->limit(20)->get(), 2000);
        $start = hrtime(true);
        $streamed = 0;

        foreach ($repository->query()->cursor(500) as $ignored) {
            ++$streamed;
        }

        $streamSeconds = (hrtime(true) - $start) / 1e9;
        $insertStart = hrtime(true);
        $fresh = $orm->manager();

        for ($i = 0; $i < 1000; ++$i) {
            $fresh->persist(new Customer(name: 'N' . $i, email: 'n' . $i . '@example.com'));
        }

        $fresh->flush();
        $flushSeconds = (hrtime(true) - $insertStart) / 1e9;

        // Assert (report)
        $this->line('orm', 'find(id) with a new manager (query + hydrate)', $find);
        $this->line('orm', 'readOnly where(email) first()', $first);
        $this->line('orm', 'readOnly sortBy + paginate(page 3, 20)', $page);
        $this->line('orm', 'request-style filter (status + 3 names)', $filter);
        fwrite(\STDERR, \sprintf("\n[hardening] orm   cursor() streaming        %d rows in %.1f ms (%.2f us/row)", $streamed, $streamSeconds * 1000, $streamSeconds / max(1, $streamed) * 1e6));
        fwrite(\STDERR, \sprintf("\n[hardening] orm   persist+flush 1000 rows    %.1f ms (%.2f us/row)", $flushSeconds * 1000, $flushSeconds / 1000 * 1e6));
        self::assertSame(2000, $streamed);
    }

    /**
     * @param list<float> $samples seconds
     */
    private function line(string $package, string $label, array $samples): void
    {
        sort($samples);
        $n = \count($samples);
        $pick = static fn(float $p): float => $samples[(int) min($n - 1, floor($p * $n))] * 1e6;
        fwrite(\STDERR, \sprintf("\n[hardening] %-5s %-50s p50 %8.1f us  p95 %8.1f us  p99 %8.1f us", $package, $label, $pick(0.5), $pick(0.95), $pick(0.99)));
    }

    /**
     * @return list<float> seconds
     */
    private function sample(callable $work, int $n): array
    {
        for ($i = 0; $i < min(300, $n); ++$i) {
            $work();
        }

        $samples = [];

        for ($i = 0; $i < $n; ++$i) {
            $start = hrtime(true);
            $work();
            $samples[] = (hrtime(true) - $start) / 1e9;
        }

        return $samples;
    }
}
