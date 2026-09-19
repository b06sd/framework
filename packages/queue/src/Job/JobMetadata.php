<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

/**
 * Validated, immutable description of one job class.
 */
final readonly class JobMetadata
{
    /**
     * @param class-string       $class
     * @param list<Field>        $fields       constructor parameters, in order
     * @param list<class-string> $dependencies handle() parameter types, in order
     */
    public function __construct(
        public string $class,
        public string $name,
        public JobOptions $options,
        public array $fields,
        public array $dependencies,
    ) {}
}
