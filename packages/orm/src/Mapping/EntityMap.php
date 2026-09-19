<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

/**
 * Describes how one entity class maps to a table. Maps are plain classes you write and version;
 * `trunk build` compiles them to generated metadata and hydrators. Entities stay plain PHP.
 *
 * @api
 */
interface EntityMap
{
    /**
     * @return class-string the entity this map describes
     */
    public function entity(): string;

    public function define(MapBuilder $map): void;
}
