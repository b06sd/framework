<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Orm;

use Trunk\Database\Query\QueryBuilder;
use Trunk\Orm\Repository\Scope;

final class TotalScope implements Scope
{
    public function apply(QueryBuilder $query): QueryBuilder
    {
        return $query->where('total', '>', 5);
    }
}
