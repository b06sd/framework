<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

use Trunk\Container\Scopable;
use Trunk\Contracts\Clock;
use Trunk\Database\Connection\Connection;
use Trunk\Database\Query\Raw;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Queue;

/**
 * Runs one chunk of a pipeline: read, transform, write, advance, dispatch the next chunk (or mark
 * the run complete when the source is exhausted). All of it lives here rather than in the generated
 * App\Jobs\RunPipelineChunk, which stays a one-line pass-through.
 *
 * Dispatching the next chunk needs a real, type-checked Job instance of an application class this
 * package cannot import — solved by going through the same JobRegistry/JobDefinition::decode() the
 * queue worker itself uses to turn a stored payload back into a job, keyed by the job's compiled
 * name (its class name; see JobMetadataFactory), never by constructing `new $class(...)` on an
 * unverifiable constructor shape.
 *
 * A Pipeline is resolved through a fresh scope (`Scopable::beginScope()`), the same pattern
 * ApplicationCommands/DoctorCommand use — Trunk's container only offers itself as Scopable, never as
 * a raw ContainerInterface a service could use as a locator.
 *
 * @internal
 */
final readonly class PipelineRunner
{
    public function __construct(
        private Scopable $container,
        private Connection $connection,
        private Clock $clock,
        private JobRegistry $jobs,
        private Queue $queue,
        private string $table = 'trunk_pipeline_runs',
        private string $job = 'App\\Jobs\\RunPipelineChunk',
    ) {}

    /**
     * @param class-string<Pipeline> $pipeline
     */
    public function runChunk(string $pipeline, int $runId, int $offset, int $limit): void
    {
        $builder = new PipelineBuilder();
        $this->resolve($pipeline)->define($builder);

        $read = 0;
        $survivors = [];

        foreach ($builder->source()->read($offset, $limit) as $record) {
            ++$read;

            foreach ($builder->stages() as $stage) {
                $record = $stage->process($record);

                if ($record === null) {
                    continue 2;
                }
            }

            $survivors[] = $record;
        }

        if ($read === 0) {
            $this->complete($runId);

            return;
        }

        if ($survivors !== []) {
            $builder->sink()->write($survivors);
        }

        $this->advance($runId, $read, \count($survivors));
        $this->dispatch($pipeline, $runId, $offset + $read, $limit);
    }

    /**
     * Starts a run: inserts the tracking row and dispatches its first chunk. Returns the run id.
     *
     * @param class-string<Pipeline> $pipeline
     */
    public function start(string $pipeline): int
    {
        $builder = new PipelineBuilder();
        $this->resolve($pipeline)->define($builder);

        $now = $this->clock->now();
        $runId = (int) $this->connection->table($this->table)->insertGetId([
            'pipeline' => $pipeline,
            'status' => 'running',
            'cursor' => 0,
            'records_processed' => 0,
            'chunk_size' => $builder->chunkSize(),
            'started_at' => $now,
            'updated_at' => $now,
        ]);

        $this->dispatch($pipeline, $runId, 0, $builder->chunkSize());

        return $runId;
    }

    /**
     * Re-dispatches one chunk job at a run's last recorded cursor — for a run stuck `running` with
     * no job in flight (a crash between a chunk succeeding and the next one being dispatched).
     */
    public function resume(int $runId): void
    {
        $run = $this->connection->table($this->table)->find($runId);

        if ($run === null || !\is_string($run['pipeline']) || !\is_string($run['status']) || !is_numeric($run['cursor']) || !is_numeric($run['chunk_size'])) {
            throw new Exception\PipelineException(\sprintf('There is no pipeline run #%d.', $runId));
        }

        if ($run['status'] !== 'running') {
            throw new Exception\PipelineException(\sprintf('Pipeline run #%d is %s, not running.', $runId, $run['status']));
        }

        $this->dispatch($run['pipeline'], $runId, (int) $run['cursor'], (int) $run['chunk_size']);
    }

    /**
     * @param class-string<Pipeline> $class
     */
    private function resolve(string $class): Pipeline
    {
        $pipeline = $this->container->beginScope()->get($class);

        return $pipeline instanceof Pipeline ? $pipeline : throw new Exception\PipelineException(\sprintf('%s does not implement %s.', $class, Pipeline::class));
    }

    private function dispatch(string $pipeline, int $runId, int $offset, int $limit): void
    {
        $job = $this->jobs->definition($this->job)->decode(['pipeline' => $pipeline, 'runId' => $runId, 'offset' => $offset, 'limit' => $limit]);
        $this->queue->dispatch($job);
    }

    private function advance(int $runId, int $read, int $survived): void
    {
        $now = $this->clock->now();
        $this->connection->table($this->table)->where('id', '=', $runId)->update([
            'cursor' => new Raw('cursor + ?', [$read]),
            'records_processed' => new Raw('records_processed + ?', [$survived]),
            'updated_at' => $now,
        ]);
    }

    private function complete(int $runId): void
    {
        $now = $this->clock->now();
        $this->connection->table($this->table)->where('id', '=', $runId)->update([
            'status' => 'completed',
            'updated_at' => $now,
            'completed_at' => $now,
        ]);
    }
}
