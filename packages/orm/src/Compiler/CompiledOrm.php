<?php

declare(strict_types=1);

namespace Trunk\Orm\Compiler;

use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Mapping\EntityMetadata;
use Trunk\Orm\Mapping\Mapper;
use Trunk\Orm\Mapping\MappingRegistry;

/**
 * What `build/orm.php` returns: validated metadata plus generated mappers.
 */
final readonly class CompiledOrm implements MappingRegistry
{
    /**
     * @param array<class-string, EntityMetadata> $metadata
     * @param array<class-string, Mapper>         $mappers
     */
    public function __construct(private array $metadata, private array $mappers) {}

    public function metadata(string $class): EntityMetadata
    {
        return $this->metadata[$class] ?? throw new OrmException(\sprintf('%s has no entity map. List its map in orm.maps and run `trunk build`.', $class));
    }

    public function mapper(string $class): Mapper
    {
        return $this->mappers[$class] ?? throw new OrmException(\sprintf('%s has no entity map. List its map in orm.maps and run `trunk build`.', $class));
    }

    public function entities(): array
    {
        return array_keys($this->metadata);
    }
}
