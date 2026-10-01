<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * Loggers by category in a project made by the real `trunk new`: the documented example, with a
 * category more verbose than the application's level, in development and in the compiled build.
 */
final class LoggingEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_a_category_logs_at_its_own_level_in_development_and_production(): void
    {
        // Arrange: the application logs warnings and up; App\Controllers is turned up to debug
        $project = $this->project = new ScaffoldedProject('ledger', 'api');
        file_put_contents($project->directory . '/app/Controllers/InvoiceController.php', <<<'CONTROLLER'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Psr\Http\Message\ResponseInterface;
            use Psr\Log\LoggerInterface;
            use Trunk\Http\Response\ResponseBuilder;
            use Trunk\Logging\Logs;

            final readonly class InvoiceController
            {
                private LoggerInterface $logger;

                public function __construct(Logs $logs, private ResponseBuilder $responses, private LoggerInterface $plain)
                {
                    $this->logger = $logs->for(self::class);
                }

                public function send(): ResponseInterface
                {
                    $this->logger->debug('Rendering invoice {id}', ['id' => 7]);
                    $this->logger->info('Invoice {id} sent', ['id' => 7]);
                    $this->plain->info('Not shown: the application level is warning');

                    return $this->responses->json(['sent' => true]);
                }
            }
            CONTROLLER);
        $routes = $project->directory . '/routes/api.php';
        file_put_contents($routes, str_replace("    \$routes->group('/api'", "    \$routes->get('/invoices/send', [\\App\\Controllers\\InvoiceController::class, 'send']);\n    \$routes->group('/api'", (string) file_get_contents($routes)));
        $config = $project->directory . '/config/logging.php';
        file_put_contents($config, str_replace("'levels' => [],", "'levels' => ['App\\Controllers' => 'debug'],", (string) file_get_contents($config), $replaced));
        file_put_contents($project->directory . '/.env', "\nLOG_LEVEL=warning\nLOG_CHANNEL=stderr\n", \FILE_APPEND);

        // Act
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [, $productionBody, $production] = $project->request('GET', '/invoices/send', 'production');
        [, $developmentBody, $development] = $project->request('GET', '/invoices/send', 'local');

        // Assert
        self::assertSame(1, $replaced, 'the scaffolded config/logging.php has a levels entry');
        self::assertSame(0, $buildCode, $buildOut);
        self::assertSame('{"sent":true}', $productionBody);
        self::assertSame('{"sent":true}', $developmentBody);

        $records = array_map(static fn(string $line): mixed => json_decode($line, true), array_values(array_filter(explode("\n", $production))));
        self::assertSame([['DEBUG', 'Rendering invoice 7', 'App\Controllers\InvoiceController'], ['INFO', 'Invoice 7 sent', 'App\Controllers\InvoiceController']], array_map(static fn(mixed $r): array => \is_array($r) ? [$r['level'] ?? null, $r['message'] ?? null, $r['category'] ?? null] : [], $records), $production);

        self::assertStringContainsString('DEBUG [App\Controllers\InvoiceController] Rendering invoice 7', $development);
        self::assertStringContainsString('INFO [App\Controllers\InvoiceController] Invoice 7 sent', $development);
        self::assertStringNotContainsString('Not shown', $development . $production);
    }

    public function test_the_build_refuses_a_levels_map_it_cannot_use(): void
    {
        // Arrange
        $project = $this->project = new ScaffoldedProject('ledger', 'api');
        $config = $project->directory . '/config/logging.php';
        file_put_contents($config, str_replace("'levels' => [],", "'levels' => ['App\\Controllers' => 'verbose'],", (string) file_get_contents($config)));

        // Act
        [$code, $out, $err] = $project->trunk(['build']);

        // Assert
        self::assertNotSame(0, $code);
        self::assertStringContainsString('logging.levels: "App\Controllers" must be one of: debug, info', $out . $err);
    }
}
