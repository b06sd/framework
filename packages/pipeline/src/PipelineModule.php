<?php

declare(strict_types=1);

namespace Trunk\Pipeline;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Scopable;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Clock;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Database\Connection\Connection;
use Trunk\Queue\Job\JobRegistry;
use Trunk\Queue\Queue;
use Trunk\Queue\QueueModule;

/**
 * Registers the pipeline runner. A Pipeline is container-resolved (unlike EntityMap/Job), so there
 * is no build-time code generation here — only declaring every configured pipeline class as a
 * container root, so the compiled container knows to wire it (see docs/pipelines.md for why a
 * Pipeline cannot safely be validated by constructing it at build time the way EntityMap/Job are).
 *
 * @api
 */
final class PipelineModule implements Module, BuildContributor, ModuleDependencies
{
    public function requires(): array
    {
        return [QueueModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->service(PipelineRunner::class, PipelineRunner::class, [
            new Reference(Scopable::class),
            new Reference(Connection::class),
            new Reference(Clock::class),
            new Reference(JobRegistry::class),
            new Reference(Queue::class),
            new ConfigValue('pipeline.table', 'string'),
            new ConfigValue('pipeline.job', 'string'),
        ]);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        $pipelines = array_values(array_filter($context->configuration->array('pipeline.pipelines'), is_string(...)));

        return new BuildContribution($pipelines);
    }
}
