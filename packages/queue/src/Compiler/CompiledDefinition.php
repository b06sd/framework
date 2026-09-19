<?php

declare(strict_types=1);

namespace Trunk\Queue\Compiler;

use Closure;
use Psr\Container\ContainerInterface;
use Trunk\Queue\Job\Job;
use Trunk\Queue\Job\JobDefinition;
use Trunk\Queue\Job\JobMetadata;

/**
 * Wraps the closures generated into build/queue.php. No reflection.
 */
final readonly class CompiledDefinition implements JobDefinition
{
    /**
     * @param Closure(Job): array<string, mixed>                $encode
     * @param Closure(array<array-key, mixed>): Job             $decode
     * @param Closure(ContainerInterface, Job): void            $invoke
     */
    public function __construct(
        private JobMetadata $metadata,
        private Closure $encode,
        private Closure $decode,
        private Closure $invoke,
    ) {}

    public function metadata(): JobMetadata
    {
        return $this->metadata;
    }

    public function encode(Job $job): array
    {
        return ($this->encode)($job);
    }

    public function decode(array $data): Job
    {
        return ($this->decode)($data);
    }

    public function invoke(ContainerInterface $scope, Job $job): void
    {
        ($this->invoke)($scope, $job);
    }
}
