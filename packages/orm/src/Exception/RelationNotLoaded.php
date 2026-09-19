<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use LogicException;

/**
 * @api
 */
final class RelationNotLoaded extends LogicException
{
    public static function for(string $entity, string $relation): self
    {
        return new self(\sprintf('The "%s" relation of %s was not loaded. Trunk never queries lazily; load it explicitly with ->with(\'%s\') on the query.', $relation, $entity, $relation));
    }
}
