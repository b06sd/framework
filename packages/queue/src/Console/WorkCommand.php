<?php

declare(strict_types=1);

namespace Trunk\Queue\Console;

use InvalidArgumentException;
use Trunk\Contracts\Console\Command;
use Trunk\Contracts\Console\CommandDefinition;
use Trunk\Contracts\Console\CommandInput;
use Trunk\Contracts\Console\CommandOutput;
use Trunk\Contracts\Console\Exception\UsageException;
use Trunk\Foundation\Configuration;
use Trunk\Lifecycle\MemoryPolicy;
use Trunk\Queue\Worker\Worker;
use Trunk\Queue\Worker\WorkerOptions;

/**
 * `trunk queue:work`: runs jobs until told to stop. Run it under a process supervisor and let it
 * exit and restart regularly (--max-time, --max-jobs, --memory): singletons live as long as the process.
 */
final readonly class WorkCommand implements Command
{
    public function __construct(private Worker $worker, private Configuration $configuration) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition('queue:work', 'Run queued jobs', options: [
            '--queue=a,b' => 'queues to work, highest priority first (default: default)',
            '--once' => 'run at most one job, then exit',
            '--stop-when-empty' => 'exit when no job is available',
            '--max-jobs=N' => 'exit after N jobs',
            '--max-time=S' => 'exit after S seconds',
            '--memory=MB' => 'exit when memory use reaches MB (default from queue.worker.memory_limit)',
            '--sleep=S' => 'seconds to wait when idle (default 3)',
            '--gc-interval=N' => 'run a garbage-collection cycle every N jobs (default from queue.worker.gc_interval)',
        ]);
    }

    public function handle(CommandInput $input, CommandOutput $output): int
    {
        try {
            $options = new WorkerOptions(
                array_map(trim(...), explode(',', $input->option('queue', 'default') ?? 'default')),
                $this->number($input, 'max-jobs', $this->setting('max_jobs', 0)),
                $this->number($input, 'max-time', $this->setting('max_runtime', 0)),
                $this->number($input, 'memory', $this->megabytes('memory_limit', 0)),
                $this->number($input, 'sleep', $this->setting('sleep', 3)),
                $input->flag('once'),
                $input->flag('stop-when-empty'),
                $this->number($input, 'gc-interval', $this->setting('gc_interval', 100)),
                $this->megabytes('growth_warn', 64),
            );
        } catch (InvalidArgumentException $e) {
            throw new UsageException($e->getMessage());
        }

        $output->info('Working ' . implode(', ', $options->queues) . ' (stop with Ctrl+C or SIGTERM).');
        $report = $this->worker->run($options);
        $output->success(\sprintf('Stopped (%s): %d succeeded, %d retried, %d failed, %d lost.', $report->stoppedBecause, $report->succeeded, $report->retried, $report->failed, $report->lost));

        return 0;
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->configuration->has('queue.worker.' . $key) ? $this->configuration->get('queue.worker.' . $key) : null;

        return \is_int($value) ? $value : $default;
    }

    private function megabytes(string $key, int $default): int
    {
        $value = $this->configuration->has('queue.worker.' . $key) ? $this->configuration->get('queue.worker.' . $key) : null;

        return \is_string($value) || \is_int($value) ? (int) ceil(MemoryPolicy::bytes((string) $value) / 1048576) : $default;
    }

    private function number(CommandInput $input, string $name, int $default): int
    {
        $value = $input->option($name);

        if ($value === null) {
            return $default;
        }

        if (preg_match('/^\d{1,9}$/D', $value) !== 1) {
            throw new UsageException('--' . $name . ' must be a whole number.');
        }

        return (int) $value;
    }
}
