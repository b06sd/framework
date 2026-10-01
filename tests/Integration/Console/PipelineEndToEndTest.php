<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Console;

use PHPUnit\Framework\TestCase;
use Trunk\Tests\Support\ScaffoldedProject;

/**
 * The real `trunk` binary and a real queue worker: a pipeline moves rows from a CSV into a database
 * table in more than one chunk (proving the "dispatch the next chunk" chain actually works, not just
 * one chunk), and a stalled run can be resumed.
 */
final class PipelineEndToEndTest extends TestCase
{
    private ?ScaffoldedProject $project = null;

    protected function tearDown(): void
    {
        $this->project?->cleanUp();
    }

    public function test_a_pipeline_moves_every_row_across_several_chunks_and_the_run_completes(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['package:install', 'pipeline']);
        $project->trunk(['queue:table']);
        $project->trunk(['pipeline:table']);
        $project->trunk(['migrate']);
        file_put_contents($project->directory . '/customers.csv', "name,email\nAda,ada@example.com\nGrace,grace@example.com\nAlan,alan@example.com\nKatherine,katherine@example.com\nMargaret,margaret@example.com\n");
        $project->trunk(['make:migration', 'create_customers_table']);
        $migration = (glob($project->directory . '/database/migrations/*_create_customers_table.php') ?: [])[0] ?? '';
        file_put_contents($migration, "<?php\n\ndeclare(strict_types=1);\n\nuse Trunk\\Database\\Migration\\Migration;\nuse Trunk\\Database\\Schema\\Blueprint;\nuse Trunk\\Database\\Schema\\Schema;\n\nreturn new Migration(\n    up: function (Schema \$schema): void {\n        \$schema->create('customers', function (Blueprint \$t): void {\n            \$t->id();\n            \$t->string('name');\n            \$t->string('email');\n        });\n    },\n    down: function (Schema \$schema): void {\n        \$schema->dropIfExists('customers');\n    },\n);\n");
        $project->trunk(['migrate']);
        $project->trunk(['make:pipeline', 'ImportCustomers']);
        $this->write($project, 'app/Pipelines/ImportCustomers.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Pipelines;\n\nuse Trunk\\Database\\Connection\\Connection;\nuse Trunk\\Pipeline\\Pipeline;\nuse Trunk\\Pipeline\\PipelineBuilder;\nuse Trunk\\Pipeline\\Sink\\DatabaseSink;\nuse Trunk\\Pipeline\\Source\\CsvSource;\n\nfinal readonly class ImportCustomers implements Pipeline\n{\n    public function __construct(private Connection \$db) {}\n\n    public function define(PipelineBuilder \$pipeline): void\n    {\n        \$pipeline->from(new CsvSource(dirname(__DIR__, 2) . '/customers.csv'))\n            ->into(new DatabaseSink(\$this->db, 'customers'))\n            ->chunk(2);\n    }\n}\n");

        // Act
        [$listBefore, $listBeforeOut] = $project->trunk(['pipeline:status']);
        [$runCode, $runOut] = $project->trunk(['pipeline:run', 'ImportCustomers']);
        [$workCode, $workOut] = $project->trunk(['queue:work', '--queue=pipelines', '--stop-when-empty']);
        [$statusCode, $statusOut] = $project->trunk(['pipeline:status']);

        // Assert
        self::assertSame(0, $listBefore, $listBeforeOut);
        self::assertStringContainsString('No pipeline runs found.', $listBeforeOut);
        self::assertSame(0, $runCode, $runOut);
        self::assertStringContainsString('Started run #1', $runOut);
        self::assertSame(0, $workCode, $workOut);
        self::assertSame(0, $statusCode, $statusOut);
        self::assertStringContainsString('completed', $statusOut);
        self::assertStringContainsString('5', $statusOut, 'all 5 records processed, not just the first chunk of 2');

        [, $names] = $this->query($project, 'SELECT name FROM customers ORDER BY id');
        self::assertSame("Ada\nGrace\nAlan\nKatherine\nMargaret", trim($names));
    }

    public function test_a_stalled_run_is_resumed_from_its_last_recorded_cursor(): void
    {
        // Arrange
        $this->project = $project = new ScaffoldedProject('shop', 'api');
        $project->trunk(['package:install', 'queue']);
        $project->trunk(['package:install', 'console']);
        $project->trunk(['package:install', 'pipeline']);
        $project->trunk(['queue:table']);
        $project->trunk(['pipeline:table']);
        $project->trunk(['migrate']);
        $project->trunk(['make:pipeline', 'ImportNumbers']);
        $this->write($project, 'app/Pipelines/ImportNumbers.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Pipelines;\n\nuse Trunk\\Pipeline\\Pipeline;\nuse Trunk\\Pipeline\\PipelineBuilder;\nuse Trunk\\Pipeline\\Sink\\ArraySink;\nuse Trunk\\Pipeline\\Source\\ArraySource;\n\nfinal readonly class ImportNumbers implements Pipeline\n{\n    public function define(PipelineBuilder \$pipeline): void\n    {\n        \$pipeline->from(new ArraySource([1, 2, 3]))->into(new ArraySink())->chunk(10);\n    }\n}\n");

        // Act: start a run, then simulate the dispatched chunk job being lost (a crash) before any worker claims it
        [$runCode, $runOut] = $project->trunk(['pipeline:run', 'ImportNumbers']);
        $this->query($project, "DELETE FROM trunk_jobs WHERE job = 'App\\Jobs\\RunPipelineChunk'");
        [$noJobsCode, $noJobsOut] = $project->trunk(['queue:work', '--queue=pipelines', '--stop-when-empty']);
        [$resumeCode, $resumeOut] = $project->trunk(['pipeline:resume', '1']);
        [$workCode, $workOut] = $project->trunk(['queue:work', '--queue=pipelines', '--stop-when-empty']);
        [$statusCode, $statusOut] = $project->trunk(['pipeline:status']);

        // Assert
        self::assertSame(0, $runCode, $runOut);
        self::assertSame(0, $noJobsCode, $noJobsOut);
        self::assertSame(0, $resumeCode, $resumeOut);
        self::assertStringContainsString('Re-dispatched', $resumeOut);
        self::assertSame(0, $workCode, $workOut);
        self::assertSame(0, $statusCode, $statusOut);
        self::assertStringContainsString('completed', $statusOut);
    }

    private function write(ScaffoldedProject $project, string $relative, string $contents): void
    {
        file_put_contents($project->directory . '/' . $relative, $contents);
    }

    /**
     * Runs a query against the project's own sqlite database with the `sqlite3` CLI, since the test
     * process has no PHP connection into the scaffolded project's own storage/database.sqlite.
     *
     * @return array{int, string}
     */
    private function query(ScaffoldedProject $project, string $sql): array
    {
        $process = proc_open(['sqlite3', $project->directory . '/storage/database.sqlite', $sql], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = stream_get_contents($pipes[1]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out];
    }
}
