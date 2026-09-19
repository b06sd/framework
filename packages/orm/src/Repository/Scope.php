<?php

declare(strict_types=1);

namespace Trunk\Orm\Repository;

use Trunk\Database\Query\QueryBuilder;

/**
 * A global scope: narrows every query of an entity that declares it in its map (`->scope(...)`).
 * It is applied on every read path, including eager-loaded relations, and can only be skipped
 * with the explicit, greppable `Query::withoutScope()`.
 *
 * @api
 */
interface Scope
{
    public function apply(QueryBuilder $query): QueryBuilder;
}
