<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Foundation\Logging\LoggingModule;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Modules\LeakyTransactionModule;
use Trunk\Tests\Support\KernelHarness;

/**
 * A handler that leaves a transaction open has its writes rolled back after the request. The client
 * must hear about that: a success it returned becomes a 500, in both container modes.
 */
final class DiscardedWorkKernelTest extends TestCase
{
    /** @var list<KernelHarness> */
    private array $harnesses = [];

    protected function tearDown(): void
    {
        foreach ($this->harnesses as $harness) {
            $harness->cleanUp();
        }
    }

    public function test_a_success_whose_writes_were_rolled_back_becomes_a_500(): void
    {
        foreach (['development' => false, 'compiled' => true] as $mode => $compiled) {
            // Arrange
            $kernel = $this->kernel($compiled);

            // Act
            $response = $this->send($kernel, 'POST', '/leaky/created');
            $after = $this->send($kernel, 'GET', '/leaky/notes');

            // Assert
            self::assertSame(500, $response->getStatusCode(), $mode);
            self::assertStringContainsString('"code":"INTERNAL_ERROR"', (string) $response->getBody(), $mode);
            self::assertStringNotContainsString('saved', (string) $response->getBody(), $mode);
            self::assertStringNotContainsString('transaction', (string) $response->getBody(), $mode . ': the reason stays in the log');
            self::assertSame('{"notes":[],"open":0}', (string) $after->getBody(), $mode . ': nothing was kept and the next request is outside the old transaction');
        }
    }

    public function test_an_error_response_is_kept_since_it_already_says_nothing_was_done(): void
    {
        // Arrange
        $kernel = $this->kernel(true);

        // Act
        $response = $this->send($kernel, 'POST', '/leaky/refused');

        // Assert
        self::assertSame(422, $response->getStatusCode());
    }

    public function test_committed_work_is_untouched(): void
    {
        // Arrange
        $kernel = $this->kernel(true);

        // Act
        $response = $this->send($kernel, 'POST', '/leaky/committed');
        $after = $this->send($kernel, 'GET', '/leaky/notes');

        // Assert
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"notes":["kept"],"open":0}', (string) $after->getBody());
    }

    private function send(HttpKernel $kernel, string $method, string $path): ResponseInterface
    {
        return $kernel->handle(new ServerRequest($method, 'http://trunk.dev' . $path, ['Accept' => 'application/json']));
    }

    private function kernel(bool $compiled): HttpKernel
    {
        $harness = new KernelHarness(modules: [LoggingModule::class, DiagnosticsModule::class, DatabaseModule::class, HttpModule::class, LeakyTransactionModule::class], configuration: [
            'logging' => ['channel' => 'null'],
            'database' => ['default' => 'main', 'log_queries' => false, 'migrations' => sys_get_temp_dir() . '/trunk-no-such-migrations', 'connections' => ['main' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ]);
        $this->harnesses[] = $harness;

        return $compiled ? $harness->compiled() : $harness->development();
    }
}
