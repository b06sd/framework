<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use Psr\Container\ContainerInterface;

/**
 * Everything the queue does with one job class: turn it into payload data, back again, and run it.
 */
interface JobDefinition
{
    public function metadata(): JobMetadata;

    /**
     * @return array<string, mixed> JSON-safe payload data
     */
    public function encode(Job $job): array;

    /**
     * @param array<array-key, mixed> $data decoded JSON
     *
     * @throws \Trunk\Queue\Exception\InvalidPayload
     */
    public function decode(array $data): Job;

    /**
     * Calls handle() with its dependencies taken from the given (per-job) container scope.
     */
    public function invoke(ContainerInterface $scope, Job $job): void;
}
