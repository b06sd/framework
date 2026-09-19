<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary and public/index.php: enable the queue, create the tables, dispatch a job
 * over HTTP, run the worker, and check the effect in development and from the compiled build.
 */
final class QueueEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_jobs_are_dispatched_over_http_and_run_by_the_worker_in_both_modes(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        file_put_contents($project->directory . '/.env', "LOG_CHANNEL=file\nLOG_LEVEL=debug\n", FILE_APPEND);
        [$tableCode] = $project->trunk(['queue:table']);
        [$tableAgain] = $project->trunk(['queue:table']);
        [$migrateCode, $migrateOut] = $project->trunk(['migrate']);
        [$makeCode] = $project->trunk(['make:job', 'WriteMarker']);
        [$overwrite] = $project->trunk(['make:job', 'WriteMarker']);
        [$bad] = $project->trunk(['make:job', '../Evil']);
        $this->write($project, 'app/Jobs/WriteMarker.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Jobs;\n\nuse Trunk\\Foundation\\Runtime;\nuse Trunk\\Queue\\Job\\Job;\n\nfinal readonly class WriteMarker implements Job\n{\n    public function __construct(public int \$id, public string \$note = '') {}\n\n    public function handle(Runtime \$runtime): void\n    {\n        file_put_contents(\$runtime->basePath . '/storage/marker-' . \$this->id . '.txt', \$this->note);\n    }\n}\n");
        $this->write($project, 'app/Controllers/JobController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse App\\Jobs\\WriteMarker;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Queue\\Queue;\n\nfinal readonly class JobController\n{\n    public function __construct(private Queue \$queue, private ResponseBuilder \$responses) {}\n\n    public function create(): ResponseInterface\n    {\n        return \$this->responses->json(['id' => \$this->queue->dispatch(new WriteMarker(5, 'hello'))]);\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/api.php');
        $this->write($project, 'routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->post('/jobs', [\\App\\Controllers\\JobController::class, 'create']);\n        \$api->get('/customers', ", $routes));

        // Act
        [, $devDispatched] = $project->request('POST', '/api/jobs', 'local');
        [$devWorkCode, $devWorkOut] = $project->trunk(['queue:work', '--stop-when-empty']);
        $devMarker = @file_get_contents($project->directory . '/storage/marker-5.txt');
        unlink($project->directory . '/storage/marker-5.txt');
        [$buildCode, $buildOut] = $project->trunk(['build']);
        [, $prodDispatched] = $project->request('POST', '/api/jobs', 'production');
        [$prodWorkCode, $prodWorkOut] = $project->trunk(['queue:work', '--stop-when-empty'], null, ['APP_ENV' => 'production']);
        $prodMarker = @file_get_contents($project->directory . '/storage/marker-5.txt');
        [, $failedOut] = $project->trunk(['queue:failed']);
        [$badOption] = $project->trunk(['queue:work', '--max-jobs=abc']);

        // Assert
        self::assertSame(0, $tableCode);
        self::assertNotSame(0, $tableAgain, 'queue:table never writes the migration twice.');
        self::assertSame(0, $migrateCode, $migrateOut);
        self::assertSame(0, $makeCode);
        self::assertNotSame(0, $overwrite);
        self::assertNotSame(0, $bad);
        self::assertSame('{"id":1}', $devDispatched);
        self::assertSame(0, $devWorkCode, $devWorkOut);
        self::assertStringContainsString('1 succeeded', $devWorkOut);
        self::assertSame('hello', $devMarker);
        self::assertSame(0, $buildCode, $buildOut);
        self::assertFileExists($project->directory . '/build/queue.php');
        self::assertSame('{"id":2}', $prodDispatched);
        self::assertSame(0, $prodWorkCode, $prodWorkOut);
        self::assertStringContainsString('1 succeeded', $prodWorkOut);
        self::assertSame('hello', $prodMarker);
        self::assertStringContainsString('No failed jobs', $failedOut);
        self::assertNotSame(0, $badOption);
        $logs = '';
        foreach (glob($project->directory . '/storage/logs/trunk-*.log') ?: [] as $file) {
            $logs .= (string) file_get_contents($file);
        }
        self::assertSame(2, preg_match_all('/"originRequestId":"req_[0-9A-Z]{26}"/', $logs), 'each job log names the HTTP request that queued it (development and production)');
    }

    public function test_a_failing_job_is_retried_then_listed_as_failed_and_can_be_retried_by_hand(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['queue:table']);
        $project->trunk(['migrate']);
        $this->write($project, 'app/Jobs/AlwaysFails.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Jobs;\n\nuse Trunk\\Queue\\Job\\Job;\nuse Trunk\\Queue\\Job\\JobOptions;\n\nfinal readonly class AlwaysFails implements Job\n{\n    public function __construct(public string \$secret = 'top-secret-value') {}\n\n    public function handle(): void\n    {\n        throw new \\RuntimeException('failed with ' . \$this->secret);\n    }\n\n    public static function options(): JobOptions\n    {\n        return new JobOptions(tries: 2, backoff: [0]);\n    }\n}\n");
        $this->write($project, 'app/Controllers/JobController.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Controllers;\n\nuse App\\Jobs\\AlwaysFails;\nuse Psr\\Http\\Message\\ResponseInterface;\nuse Trunk\\Http\\Response\\ResponseBuilder;\nuse Trunk\\Queue\\Queue;\n\nfinal readonly class JobController\n{\n    public function __construct(private Queue \$queue, private ResponseBuilder \$responses) {}\n\n    public function create(): ResponseInterface\n    {\n        return \$this->responses->json(['id' => \$this->queue->dispatch(new AlwaysFails())]);\n    }\n}\n");
        $routes = (string) file_get_contents($project->directory . '/routes/api.php');
        $this->write($project, 'routes/api.php', str_replace("        \$api->get('/customers', ", "        \$api->post('/jobs', [\\App\\Controllers\\JobController::class, 'create']);\n        \$api->get('/customers', ", $routes));
        $project->request('POST', '/api/jobs', 'local');
        $project->trunk(['build']);

        // Act
        [, $work] = $project->trunk(['queue:work', '--stop-when-empty'], null, ['APP_ENV' => 'production']);
        [, $failed] = $project->trunk(['queue:failed'], null, ['APP_ENV' => 'production']);
        [$retryCode, $retryOut] = $project->trunk(['queue:retry', 'all'], null, ['APP_ENV' => 'production']);
        [$badRetry] = $project->trunk(['queue:retry', '1; DROP TABLE trunk_jobs'], null, ['APP_ENV' => 'production']);
        [, $again] = $project->trunk(['queue:work', '--stop-when-empty'], null, ['APP_ENV' => 'production']);
        [, $flushOut] = $project->trunk(['queue:flush'], null, ['APP_ENV' => 'production']);
        [, $empty] = $project->trunk(['queue:failed'], null, ['APP_ENV' => 'production']);

        // Assert
        self::assertStringContainsString('1 retried, 1 failed', $work);
        self::assertStringContainsString('RuntimeException', $failed);
        self::assertStringNotContainsString('top-secret-value', $failed . $work . $again);
        self::assertSame(0, $retryCode, $retryOut);
        self::assertStringContainsString('1 job(s) put back', $retryOut);
        self::assertNotSame(0, $badRetry);
        self::assertStringContainsString('1 retried, 1 failed', $again);
        self::assertStringContainsString('1 failed job(s) deleted', $flushOut);
        self::assertStringContainsString('No failed jobs', $empty);
    }

    public function test_a_job_with_an_unsafe_constructor_fails_orm_style_validation_and_the_build_writes_nothing(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $this->write($project, 'app/Jobs/Bad.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Jobs;\n\nuse Trunk\\Queue\\Job\\Job;\n\nfinal readonly class Bad implements Job\n{\n    public function __construct(public \\stdClass \$entity) {}\n\n    public function handle(): void {}\n}\n");

        // Act
        [$buildCode, $buildOut, $buildErr] = $project->trunk(['build']);

        // Assert
        self::assertNotSame(0, $buildCode);
        self::assertStringContainsString('cannot be queued', $buildOut . $buildErr);
        self::assertFileDoesNotExist($project->directory . '/build/queue.php');
    }

    private function write(ScaffoldedProject $project, string $path, string $contents): void
    {
        file_put_contents($project->directory . '/' . $path, $contents);
    }
}
