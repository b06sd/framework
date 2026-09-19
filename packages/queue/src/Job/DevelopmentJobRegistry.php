<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use Trunk\Queue\Exception\UnknownJob;

/**
 * Builds job metadata from the configured classes on first use (development).
 */
final class DevelopmentJobRegistry implements JobRegistry
{
    /** @var array<string, JobDefinition>|null */
    private ?array $definitions = null;

    /**
     * @param list<string> $classes
     */
    public function __construct(private readonly array $classes, private readonly JobMetadataFactory $factory = new JobMetadataFactory()) {}

    public function definition(string $name): JobDefinition
    {
        return $this->all()[$name] ?? throw UnknownJob::named($name);
    }

    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, JobDefinition>
     */
    private function all(): array
    {
        return $this->definitions ??= array_map(static fn(JobMetadata $m): JobDefinition => new InterpretedDefinition($m), $this->factory->fromClasses($this->classes));
    }
}
