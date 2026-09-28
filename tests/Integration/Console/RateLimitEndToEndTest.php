<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary, a real `php -S` server and a real database: a route that opts into
 * RateLimitMiddleware answers normally under the limit and 429 with Retry-After once it is reached,
 * and a route that does not opt in is never limited.
 */
final class RateLimitEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_a_route_using_the_middleware_is_limited_and_a_route_that_does_not_use_it_is_not(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'rate-limit']);
        $project->trunk(['package:install', 'console']);
        file_put_contents($project->directory . '/.env', "RATE_LIMIT_MAX=2\nRATE_LIMIT_WINDOW=60\n", \FILE_APPEND);
        $project->trunk(['rate-limit:table']);
        $project->trunk(['migrate']);
        file_put_contents($project->directory . '/app/Controllers/PingController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\n\nfinal readonly class PingController\n{\n    public function __construct(private ResponseBuilder \$responses) {}\n\n    public function limited(): ResponseInterface\n    {\n        return \$this->responses->json(['ok' => true]);\n    }\n\n    public function open(): ResponseInterface\n    {\n        return \$this->responses->json(['ok' => true]);\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/api.php');
        $routes = str_replace("        \$api->get('/customers', ", "        \$api->get('/limited', [\\App\\Controllers\\PingController::class, 'limited'], middleware: [\\Trunk\\RateLimit\\Middleware\\RateLimitMiddleware::class]);\n        \$api->get('/open', [\\App\\Controllers\\PingController::class, 'open']);\n        \$api->get('/customers', ", $routes);
        file_put_contents($project->directory . '/routes/api.php', $routes);

        // Act
        [$firstStatus] = $this->request($project, '/api/limited');
        [$secondStatus] = $this->request($project, '/api/limited');
        [$thirdStatus, $thirdHeaders] = $this->request($project, '/api/limited');
        [$openStatus] = $this->request($project, '/api/open');

        // Assert
        self::assertSame(200, $firstStatus);
        self::assertSame(200, $secondStatus);
        self::assertSame(429, $thirdStatus);
        self::assertStringContainsString('Retry-After', $thirdHeaders);
        self::assertSame(200, $openStatus, 'a route that never lists the middleware is never limited');
    }

    /**
     * @return array{int, string}
     */
    private function request(ScaffoldedProject $project, string $path): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, (string) $error);
        $name = (string) stream_socket_get_name($socket, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($socket);
        $server = proc_open([\PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $project->directory . '/public', $project->directory . '/public/index.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $project->directory, ['APP_ENV' => 'local', 'APP_DEBUG' => '0', 'PATH' => (string) getenv('PATH')]);
        self::assertIsResource($server);

        try {
            for ($attempt = 0; $attempt < 60; ++$attempt) {
                $body = @file_get_contents('http://127.0.0.1:' . $port . $path, false, stream_context_create(['http' => ['ignore_errors' => true, 'header' => "Accept: application/json\r\n"]]));

                if ($body !== false) {
                    $headers = http_get_last_response_headers() ?? [];

                    return [(int) substr($headers[0] ?? 'HTTP/1.1 0', 9, 3), implode("\n", $headers)];
                }

                usleep(50_000);
            }

            return [0, ''];
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }
}
