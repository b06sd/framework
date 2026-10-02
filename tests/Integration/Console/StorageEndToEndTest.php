<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Trunk\Testing\TestApp;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The storage capability in a project made by the real `trunk new`: installed, built with its
 * template config (an unused s3 entry included), and used from a route that writes a file and then
 * offers it as a download.
 */
final class StorageEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    #[RunInSeparateProcess]
    public function test_installed_storage_builds_and_serves_a_stored_file_as_a_download(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('files', 'api');
        [$installCode, $installOut, $installErr] = $project->trunk(['package:install', 'storage']);
        file_put_contents($project->directory . '/app/Controllers/ReportController.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace App\Controllers;

            use Psr\Http\Message\ResponseInterface;
            use Trunk\Http\Response\ResponseBuilder;
            use Trunk\Storage\Storage;

            final readonly class ReportController
            {
                public function __construct(private Storage $storage, private ResponseBuilder $responses) {}

                public function download(): ResponseInterface
                {
                    $this->storage->disk()->write('reports/october.csv', "month,total\noctober,42\n");

                    return $this->responses->download($this->storage->disk()->readStream('reports/october.csv'), 'October report.csv', 'text/csv');
                }
            }
            PHP);
        file_put_contents($project->directory . '/routes/api.php', "<?php\n\ndeclare(strict_types=1);\n\nuse App\\Controllers\\ReportController;\nuse Trunk\\Router\\Definition\\RouteCollector;\n\nreturn static function (RouteCollector \$routes): void {\n    \$routes->get('/report', [ReportController::class, 'download']);\n};\n");

        // Act
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);
        require_once $project->directory . '/vendor/autoload.php';
        $response = TestApp::client($project->directory)->get('/report');

        // Assert
        self::assertSame(0, $installCode, $installOut . $installErr);
        self::assertSame(0, $buildCode, $buildOut . $buildErr);
        self::assertDirectoryExists($project->directory . '/storage/app');
        $response->assertOk();
        self::assertSame("month,total\noctober,42\n", $response->body());
        self::assertStringStartsWith('attachment; filename="October report.csv"', $response->header('Content-Disposition'));
        self::assertFileExists($project->directory . '/storage/app/reports/october.csv');
    }
}
