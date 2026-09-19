<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class TagMap implements EntityMap
{
    public function entity(): string
    {
        return Tag::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('tags');
        $map->id();
        $map->string('label')->filterable();
    }
}
