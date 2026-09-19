<?php

declare(strict_types=1);

namespace Trunk\Queue\Compiler;

use Trunk\Queue\Exception\UnknownJob;
use Trunk\Queue\Job\JobDefinition;
use Trunk\Queue\Job\JobRegistry;

/**
 * What `build/queue.php` returns. Lookup is by exact name in this array and nothing else.
 */
final readonly class CompiledJobs implements JobRegistry
{
    /**
     * @param array<string, JobDefinition> $definitions
     */
    public function __construct(private array $definitions) {}

    public function definition(string $name): JobDefinition
    {
        return $this->definitions[$name] ?? throw UnknownJob::named($name);
    }

    public function names(): array
    {
        return array_keys($this->definitions);
    }
}
