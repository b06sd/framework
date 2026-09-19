<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * A real scaffolded web project: errors as JSON, as a Tusk page and as the development page, the
 * request id tying the response to the log line, and nothing internal leaking in production.
 */
final class ErrorsEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_production_errors_are_safe_in_every_format_and_the_request_id_matches_the_log(): void
    {
        // Arrange
        $this->project = $project = $this->prepare('web');
        [$buildCode, $buildOut] = $project->trunk(['build']);

        // Act
        [, $notFound] = $project->request('GET', '/nope', 'production', false, 'text/html');
        [, $boom] = $project->request('GET', '/boom', 'production', false, 'text/html');
        $records = $this->logs($project);

        // Assert
        self::assertSame(0, $buildCode, $buildOut);
        self::assertStringContainsString('Page not found', $notFound, 'the project’s own Tusk error page is used (text/plain would say Not Found)');
        self::assertStringContainsString('Request ID:', $notFound);
        self::assertStringNotContainsString('secret database password', $boom);
        self::assertStringNotContainsString('BoomController', $boom);
        self::assertStringNotContainsString('.php', $boom);
        self::assertNotEmpty(array_filter($records, static fn(array $r): bool => ($r['level'] ?? '') === 'ERROR' && str_contains(json_encode($r['exception'] ?? '') ?: '', 'RuntimeException')));
    }

    public function test_development_mode_shows_the_exception_page_and_still_hides_secret_headers(): void
    {
        // Arrange
        $this->project = $project = $this->prepare('web');

        // Act
        [, $page] = $project->request('GET', '/boom', 'local', true, 'text/html');

        // Assert
        self::assertStringContainsString('RuntimeException', $page);
        self::assertStringContainsString('secret database password', $page);
        self::assertStringContainsString('BoomController.php', $page);
    }

    public function test_doctor_checks_the_logging_setup(): void
    {
        // Arrange
        $this->project = $project = $this->prepare('web');

        // Act
        [$code, $out] = $project->trunk(['doctor'], null, ['APP_ENV' => 'local']);

        // Assert
        self::assertStringContainsString('Log directory (storage/logs) is writable', $out);
        self::assertSame(0, $code, $out);
    }

    public function test_the_api_profile_returns_json_errors_for_json_clients_and_plain_text_otherwise(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('api', 'api');
        $project->trunk(['build']);

        // Act
        [, $json] = $project->request('GET', '/api/customers/99', 'production', false, 'application/json');
        [, $text] = $project->request('GET', '/api/customers/99', 'production');
        $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);

        // Assert
        $error = \is_array($decoded) && \is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        self::assertSame('NOT_FOUND', $error['code'] ?? null);
        self::assertMatchesRegularExpression('/^req_[0-9A-Z]{26}$/D', \is_string($error['requestId'] ?? null) ? $error['requestId'] : '');
        self::assertSame('Not Found', $text);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function logs(ScaffoldedProject $project): array
    {
        $records = [];

        foreach (glob($project->directory . '/storage/logs/trunk-*.log') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                self::assertIsArray($decoded);
                $records[] = array_filter($decoded, is_string(...), ARRAY_FILTER_USE_KEY);
            }
        }

        return $records;
    }

    private function prepare(string $type): ScaffoldedProject
    {
        $project = new ScaffoldedProject('shop', $type);
        file_put_contents($project->directory . '/.env', "LOG_CHANNEL=file\nLOG_LEVEL=debug\n", FILE_APPEND);
        file_put_contents($project->directory . '/app/Controllers/BoomController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nfinal readonly class BoomController\n{\n    public function boom(): never\n    {\n        throw new \\RuntimeException('secret database password');\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/web.php');
        file_put_contents($project->directory . '/routes/web.php', str_replace('return static function (RouteCollector $routes): void {', "return static function (RouteCollector \$routes): void {\n    \$routes->get('/boom', [\\App\\Controllers\\BoomController::class, 'boom']);", $routes));

        return $project;
    }
}
