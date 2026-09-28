<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Http\HttpModule;
use Trunk\Http\Response\ResponseBuilder;
use Trunk\Http\Server\ServerRequestCreator;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Definition\RouteProvider;
use Trunk\Testing\TestClient;
use Trunk\Tests\Support\KernelHarness;

/**
 * TestClient against a real kernel (no scaffolded project needed): a fixture module echoes back
 * whatever the request carried, so these tests prove the client sends what it says it sends. The
 * cookie jar and a full login are proven for real in TestingKitEndToEndTest.
 */
final class TestClientTest extends TestCase
{
    private ?KernelHarness $harness = null;

    protected function tearDown(): void
    {
        $this->harness?->cleanUp();
    }

    public function test_get_sends_a_query_string_and_reads_the_client_address(): void
    {
        // Arrange
        $client = $this->client();

        // Act
        $response = $client->withAddress('203.0.113.9')->get('/echo', ['name' => 'Ada']);

        // Assert
        $response->assertOk()->assertJson(['method' => 'GET', 'query' => ['name' => 'Ada'], 'address' => '203.0.113.9']);
    }

    public function test_post_sends_a_form_body(): void
    {
        // Arrange
        $client = $this->client();

        // Act
        $response = $client->post('/echo', ['email' => 'ada@example.com']);

        // Assert
        $response->assertOk()->assertJson(['method' => 'POST', 'form' => ['email' => 'ada@example.com']]);
    }

    public function test_put_patch_and_delete_use_the_right_method(): void
    {
        // Arrange
        $client = $this->client();

        // Act & Assert
        $client->put('/echo', ['a' => 1])->assertJson(['method' => 'PUT']);
        $client->patch('/echo', ['a' => 1])->assertJson(['method' => 'PATCH']);
        $client->delete('/echo')->assertJson(['method' => 'DELETE']);
    }

    public function test_json_sends_a_json_body_with_the_right_content_type(): void
    {
        // Arrange
        $client = $this->client();

        // Act
        $response = $client->json('POST', '/echo', ['name' => 'Ada', 'active' => true]);

        // Assert
        $response->assertJson(['method' => 'POST', 'contentType' => 'application/json', 'json' => ['name' => 'Ada', 'active' => true]]);
    }

    public function test_with_header_is_sent_on_every_request(): void
    {
        // Arrange
        $client = $this->client()->withHeader('X-Api-Key', 'secret');

        // Act
        $response = $client->get('/echo');

        // Assert
        $response->assertJson(['headers' => ['X-Api-Key' => 'secret']]);
    }

    private function client(): TestClient
    {
        $this->harness = $harness = new KernelHarness(modules: [HttpModule::class, EchoModule::class]);

        return new TestClient($harness->development());
    }
}

/**
 * @internal test fixture
 */
final class EchoModule implements Module, RouteProvider
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(EchoController::class);
    }

    public function boot(ContainerInterface $container): void {}

    public function routes(RouteCollector $routes): void
    {
        $routes->any('/echo', [EchoController::class, 'handle']);
    }
}

/**
 * @internal test fixture
 */
final readonly class EchoController
{
    public function __construct(private ResponseBuilder $responses) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $raw = (string) $request->getBody();
        $contentType = explode(';', $request->getHeaderLine('Content-Type'))[0];
        $json = $contentType === 'application/json' && $raw !== '' ? json_decode($raw, true) : null;

        return $this->responses->json([
            'method' => $request->getMethod(),
            'query' => $request->getQueryParams(),
            'form' => \is_array($body) ? $body : null,
            'json' => \is_array($json) ? $json : null,
            'contentType' => $contentType,
            'address' => $request->getAttribute(ServerRequestCreator::CLIENT_IP_ATTRIBUTE),
            'headers' => ['X-Api-Key' => $request->getHeaderLine('X-Api-Key')],
        ]);
    }
}
