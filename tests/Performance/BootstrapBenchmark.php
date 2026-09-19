<?php

declare(strict_types=1);

namespace Trunk\Tests\Performance;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Router\Compiler\RouteCompiler;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Router\Matcher\Matcher;
use Trunk\Tests\Fixtures\Controllers\PageController;
use Trunk\Tests\Fixtures\Modules\WebModule;
use Trunk\Tests\Support\KernelHarness;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * Measurements for the architecture review (numbers, not claims). Everything is printed to stderr;
 * assertions are deliberately loose so a slow machine cannot fail the suite.
 */
final class BootstrapBenchmark extends TestCase
{
    public function test_in_process_bootstrap_routing_container_and_request_latency(): void
    {
        // Arrange
        $harness = new KernelHarness(modules: [LoggingModule::class, DiagnosticsModule::class, HttpModule::class, WebModule::class], configuration: ['logging' => ['channel' => 'null', 'level' => 'info']]);

        // Act: application bootstrap (register + boot + kernel), development vs compiled
        $devBoot = [];
        for ($i = 0; $i < 200; ++$i) {
            $start = hrtime(true);
            $kernel = $harness->development(Environment::Production);
            $devBoot[] = (hrtime(true) - $start) / 1e9;
        }
        $harness->compiled(Environment::Production);
        $compiledBoot = [];
        for ($i = 0; $i < 200; ++$i) {
            $start = hrtime(true);
            $compiled = $harness->compiled(Environment::Production);
            $compiledBoot[] = (hrtime(true) - $start) / 1e9;
        }

        // Act: request latency, both modes
        $devRequest = $this->sample(fn() => $this->request($kernel, '/pages/7'), 5000);
        $compiledRequest = $this->sample(fn() => $this->request($compiled, '/pages/7'), 5000);
        $missing = $this->sample(fn() => $this->request($compiled, '/missing'), 3000);

        // Act: memory after sustained load
        $before = memory_get_usage();
        for ($i = 0; $i < 20000; ++$i) {
            $this->request($compiled, '/pages/7');
        }
        $growth = memory_get_usage() - $before;

        // Act: route resolution over a large table
        $provider = new class implements RouteProvider {
            public function routes(RouteCollector $routes): void
            {
                for ($i = 0; $i < 500; ++$i) {
                    $routes->get('/static/route' . $i, [PageController::class, 'list']);
                    $routes->get('/resource' . $i . '/{id:int}/detail', [PageController::class, 'show']);
                }
            }
        };
        $matcher = new Matcher(new RouteCompiler()->compileProviders([$provider]));
        $matchStatic = $this->sample(static fn() => $matcher->match('GET', '/static/route499'), 5000);
        $matchDynamic = $this->sample(static fn() => $matcher->match('GET', '/resource499/42/detail'), 5000);
        $matchMiss = $this->sample(static fn() => $matcher->match('GET', '/nope/nothing'), 5000);

        // Assert (report)
        $this->line('bootstrap: development (register+boot+kernel)', $this->stats($devBoot));
        $this->line('bootstrap: compiled (load artifacts+kernel)', $this->stats($compiledBoot));
        $this->line('request GET /pages/7 (development container)', $this->stats($devRequest));
        $this->line('request GET /pages/7 (compiled container)', $this->stats($compiledRequest));
        $this->line('request 404 (compiled)', $this->stats($missing));
        $this->line('route match static  (1000 routes)', $this->stats($matchStatic));
        $this->line('route match dynamic (1000 routes)', $this->stats($matchDynamic));
        $this->line('route match miss    (1000 routes)', $this->stats($matchMiss));
        $this->line('memory growth over 20000 requests', \sprintf('%d bytes (peak process %.1f MB)', $growth, memory_get_peak_usage(true) / 1048576));
        self::assertLessThan(2 * 1024 * 1024, $growth, 'sustained load must not accumulate state');
        $harness->cleanUp();
    }

    public function test_cold_process_per_request_cost_development_versus_compiled(): void
    {
        // Arrange
        $project = new ScaffoldedProject('bench', 'api');
        $project->trunk(['build']);
        $run = static function (ScaffoldedProject $p, string $environment, int $n): array {
            $samples = [];
            for ($i = 0; $i < $n; ++$i) {
                $start = hrtime(true);
                $p->request('GET', '/api/customers/2', $environment);
                $samples[] = (hrtime(true) - $start) / 1e9;
            }

            return $samples;
        };

        // Act
        $dev = $run($project, 'local', 40);
        $compiled = $run($project, 'production', 40);
        $project->cleanUp();

        // Assert (report)
        $this->line('cold PHP process per request: local (dev container)', $this->stats($dev));
        $this->line('cold PHP process per request: production (compiled)', $this->stats($compiled));
        self::assertNotEmpty($compiled);
    }

    public function test_a_database_read_through_the_query_builder_and_the_orm(): void
    {
        // Arrange
        $orm = new \Trunk\Tests\Support\OrmHarness();
        $manager = $orm->manager();
        $manager->persist(new \Trunk\Tests\Fixtures\Orm\Customer(name: 'Ada', email: 'ada@example.com'));
        $manager->flush();
        $repository = $manager->repository(\Trunk\Tests\Fixtures\Orm\Customer::class);

        // Act
        $builder = $this->sample(static fn() => $orm->connection->table('customers')->where('id', 1)->first(), 5000);
        $raw = $this->sample(static fn() => $orm->connection->select('SELECT * FROM customers WHERE id = ?', [1]), 5000);
        $readOnly = $this->sample(static fn() => $repository->query()->readOnly()->where('id', 1)->first(), 5000);
        $identity = $this->sample(static fn() => $repository->find(1), 5000);

        // Assert (report)
        $this->line('sqlite :memory: raw select by id', $this->stats($raw));
        $this->line('sqlite :memory: query builder first()', $this->stats($builder));
        $this->line('sqlite :memory: ORM readOnly query first()', $this->stats($readOnly));
        $this->line('ORM find() served from the identity map', $this->stats($identity));
        self::assertNotEmpty($builder);
    }
    private function line(string $label, string $numbers): void
    {
        fwrite(\STDERR, \sprintf("\n[bench] %-52s %s", $label, $numbers));
    }

    /**
     * @param list<float> $samples seconds
     */
    private function stats(array $samples): string
    {
        sort($samples);
        $n = \count($samples);
        $pick = static fn(float $p): float => $samples[(int) min($n - 1, floor($p * $n))] * 1e6;

        return \sprintf('p50 %8.1f us  p95 %8.1f us  p99 %8.1f us  (n=%d)', $pick(0.50), $pick(0.95), $pick(0.99), $n);
    }

    private function request(HttpKernel $kernel, string $path): void
    {
        $kernel->handle(new ServerRequest('GET', 'http://trunk.dev' . $path));
    }

    /**
     * @return list<float>
     */
    private function sample(callable $work, int $n): array
    {
        for ($i = 0; $i < 300; ++$i) {
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
