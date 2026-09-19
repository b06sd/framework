<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use Trunk\Orm\Exception\OrmException;

/**
 * Builds metadata from the configured maps on first use (development) and serves interpreted mappers.
 */
final class DevelopmentRegistry implements MappingRegistry
{
    /** @var array<class-string, EntityMetadata>|null */
    private ?array $metadata = null;

    /** @var array<class-string, Mapper> */
    private array $mappers = [];

    /**
     * @param list<string> $maps
     */
    public function __construct(private readonly array $maps, private readonly MetadataFactory $factory = new MetadataFactory()) {}

    public function metadata(string $class): EntityMetadata
    {
        return $this->all()[$class] ?? throw new OrmException(\sprintf('%s has no entity map. List its map in orm.maps.', $class));
    }

    public function mapper(string $class): Mapper
    {
        return $this->mappers[$class] ??= new InterpretedMapper($this->metadata($class));
    }

    public function entities(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<class-string, EntityMetadata>
     */
    private function all(): array
    {
        return $this->metadata ??= $this->factory->fromClasses($this->maps);
    }
}
