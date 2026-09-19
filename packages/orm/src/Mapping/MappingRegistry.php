<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use Trunk\Orm\Exception\OrmException;

interface MappingRegistry
{
    /**
     * @param class-string $class
     *
     * @throws OrmException when the class has no map
     */
    public function metadata(string $class): EntityMetadata;

    /**
     * @param class-string $class
     *
     * @throws OrmException when the class has no map
     */
    public function mapper(string $class): Mapper;

    /**
     * @return list<class-string>
     */
    public function entities(): array;
}
