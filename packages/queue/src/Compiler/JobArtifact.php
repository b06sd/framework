<?php

declare(strict_types=1);

namespace Trunk\Queue\Compiler;

use Trunk\Compiler\ArtifactLoader;
use Trunk\Compiler\Exception\ArtifactException;
use Trunk\Queue\Exception\QueueException;

final class JobArtifact
{
    public static function load(string $path): CompiledJobs
    {
        try {
            return ArtifactLoader::load($path, CompiledJobs::class, 'queue');
        } catch (ArtifactException $e) {
            throw new QueueException($e->getMessage(), previous: $e);
        }
    }
}
