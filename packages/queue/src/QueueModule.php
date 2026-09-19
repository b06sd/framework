<?php

declare(strict_types=1);

namespace Trunk\Queue;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Scopable;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Database\Connection\ConnectionManager;
use Trunk\Database\Connection\TransactionGuard;
use Trunk\Database\DatabaseModule;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Diagnostics\DiagnosticsModule;
use Trunk\Lifecycle\LifecycleManager;
use Trunk\Lifecycle\MemoryPolicy;
use Trunk\Logging\ContextHolder;
use Trunk\Observability\Metrics;
use Trunk\Observability\Tracer;
use Trunk\Queue\Compiler\JobCodeGenerator;
use Trunk\Queue\Driver\DatabaseDriverFactory;
use Trunk\Queue\Driver\QueueDriver;
use Trunk\Queue\Exception\JobMappingException;
use Trunk\Queue\Job\ConfiguredJobRegistry;
use Trunk\Queue\Job\JobMetadata;
use Trunk\Queue\Job\JobMetadataFactory;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Job\PayloadCodec;
use Trunk\Queue\Worker\Signals;
use Trunk\Queue\Worker\Sleeper;
use Trunk\Queue\Worker\SystemSleeper;
use Trunk\Queue\Worker\Worker;
use Trunk\Support\FileWriter;
use Trunk\Support\SystemClock;

/**
 * Registers the queue. Inject `Queue` to dispatch jobs. `trunk build` validates every job class
 * and writes generated codecs to build/queue.php; production runs only that generated code.
 *
 * @api
 */
final class QueueModule implements Module, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [DatabaseModule::class, DiagnosticsModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(JobRegistry::class, ConfiguredJobRegistry::class, [new Reference(Configuration::class)]);
        $builder->service(PayloadCodec::class, PayloadCodec::class, [new ConfigValue('queue.max_payload', 'int')]);
        $builder->bindDefault(Clock::class, SystemClock::class);
        $builder->service(Sleeper::class, SystemSleeper::class);
        $builder->service(Signals::class, Signals::class);
        $builder->service(DatabaseDriverFactory::class, DatabaseDriverFactory::class);
        $builder->factory(QueueDriver::class, [DatabaseDriverFactory::class, 'create'], [
            new Reference(ConnectionManager::class),
            new ConfigValue('queue.connection', 'string'),
            new ConfigValue('queue.table', 'string'),
            new ConfigValue('queue.failed_table', 'string'),
        ]);
        $builder->service(Queue::class, Queue::class, [
            new Reference(QueueDriver::class),
            new Reference(JobRegistry::class),
            new Reference(PayloadCodec::class),
            new Reference(Clock::class),
            new Reference(ContextHolder::class),
        ]);
        $builder->service(Worker::class, Worker::class, [
            new Reference(QueueDriver::class),
            new Reference(JobRegistry::class),
            new Reference(PayloadCodec::class),
            new Reference(Scopable::class),
            new Reference(Clock::class),
            new Reference(Sleeper::class),
            new Reference(Signals::class),
            new Reference(LoggerInterface::class),
            new ConfigValue('queue.store_failure_messages', 'bool'),
            new ConfigValue('queue.visibility_timeout', 'int'),
            new Reference(LifecycleManager::class),
            new Reference(ContextHolder::class),
            new Reference(TransactionGuard::class),
            new Reference(Metrics::class),
            new Reference(Tracer::class),
        ]);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        if (!$context->configuration->has('queue.jobs')) {
            return new BuildContribution();
        }

        $classes = array_values(array_filter($context->configuration->array('queue.jobs'), is_string(...)));

        try {
            $metadata = new JobMetadataFactory()->fromClasses($classes);
        } catch (JobMappingException $e) {
            throw new CompilationException($e->errors);
        }

        $visibility = $context->configuration->has('queue.visibility_timeout') ? $context->configuration->int('queue.visibility_timeout') : 600;
        $errors = $this->workerProblems($context->configuration);

        foreach ($metadata as $job) {
            if ($job->options->timeout + 30 > $visibility) {
                $errors[] = \sprintf('%s: timeout of %d seconds does not fit queue.visibility_timeout (%d). The visibility timeout must exceed every job timeout by 30 seconds, or a running job could be claimed twice.', $job->name, $job->options->timeout, $visibility);
            }
        }

        if ($errors !== []) {
            throw new CompilationException($errors);
        }

        $source = new JobCodeGenerator()->generate($metadata);

        return new BuildContribution($this->roots($metadata), [static function (string $directory) use ($source): void {
            new FileWriter()->write($directory . '/queue.php', $source);
        }]);
    }

    /**
     * Problems with `queue.worker.*` (memory limit, job and time limits, GC interval), each with the fix.
     *
     * @return list<string>
     */
    private function workerProblems(Configuration $configuration): array
    {
        if (!$configuration->has('queue.worker')) {
            return [];
        }

        $problems = [];

        foreach ($configuration->array('queue.worker') as $key => $value) {
            if (!\in_array($key, ['memory_limit', 'growth_warn', 'max_jobs', 'max_runtime', 'gc_interval', 'sleep'], true)) {
                $problems[] = \sprintf('queue.worker.%s is not a known setting (memory_limit, growth_warn, max_jobs, max_runtime, gc_interval, sleep).', $key);

                continue;
            }

            if (\in_array($key, ['memory_limit', 'growth_warn'], true)) {
                try {
                    MemoryPolicy::bytes(\is_string($value) || \is_int($value) ? (string) $value : '');
                } catch (InvalidArgumentException) {
                    $problems[] = \sprintf('queue.worker.%s must be a size such as 256M or 1G.', $key);
                }

                continue;
            }

            if (!\is_int($value) || $value < 0 || $value > 10_000_000) {
                $problems[] = \sprintf('queue.worker.%s must be a whole number of zero or more.', $key);
            }
        }

        return $problems;
    }

    /**
     * The services job handlers ask for, so the compiled container wires them.
     *
     * @param array<string, JobMetadata> $metadata
     *
     * @return list<string>
     */
    private function roots(array $metadata): array
    {
        $roots = [];

        foreach ($metadata as $job) {
            foreach ($job->dependencies as $dependency) {
                $roots[$dependency] = $dependency;
            }
        }

        return array_values($roots);
    }
}
