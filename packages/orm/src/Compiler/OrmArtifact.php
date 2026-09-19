<?php

declare(strict_types=1);

namespace Trunk\Orm\Compiler;

use Trunk\Compiler\ArtifactLoader;
use Trunk\Compiler\Exception\ArtifactException;
use Trunk\Orm\Exception\OrmException;

final class OrmArtifact
{
    public static function load(string $path): CompiledOrm
    {
        try {
            return ArtifactLoader::load($path, CompiledOrm::class, 'ORM');
        } catch (ArtifactException $e) {
            throw new OrmException($e->getMessage(), previous: $e);
        }
    }
}
