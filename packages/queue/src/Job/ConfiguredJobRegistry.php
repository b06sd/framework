<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use Trunk\Foundation\Configuration;
use Trunk\Queue\Compiler\JobArtifact;
use Trunk\Queue\Exception\QueueException;

/**
 * Chooses the registry from configuration, explicitly (like the ORM and Tusk):
 *   queue.mode = "development" -> queue.jobs (list of job classes)
 *   queue.mode = "compiled"    -> queue.build (directory holding queue.php from `trunk build`)
 */
final class ConfiguredJobRegistry implements JobRegistry
{
    private ?JobRegistry $inner = null;

    public function __construct(private readonly Configuration $configuration) {}

    public function definition(string $name): JobDefinition
    {
        return $this->inner()->definition($name);
    }

    public function names(): array
    {
        return $this->inner()->names();
    }

    private function inner(): JobRegistry
    {
        return $this->inner ??= match ($mode = $this->configuration->string('queue.mode')) {
            'compiled' => JobArtifact::load($this->configuration->string('queue.build') . '/queue.php'),
            'development' => new DevelopmentJobRegistry(array_values(array_filter($this->configuration->array('queue.jobs'), is_string(...)))),
            default => throw new QueueException(\sprintf('queue.mode must be "development" or "compiled", "%s" given.', $mode)),
        };
    }
}
