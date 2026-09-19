<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Orm\Mapping\EntityMap;
use Trunk\Orm\Mapping\MapBuilder;

final class ProfileMap implements EntityMap
{
    public function entity(): string
    {
        return Profile::class;
    }

    public function define(MapBuilder $map): void
    {
        $map->table('profiles');
        $map->id();
        $map->int('customerId');
        $map->string('bio');
    }
}
