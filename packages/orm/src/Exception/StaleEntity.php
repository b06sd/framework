<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use RuntimeException;

/**
 * Optimistic locking: the row changed (or was removed) since the entity was loaded.
 *
 * @api
 */
final class StaleEntity extends RuntimeException
{
    public static function for(string $entity): self
    {
        return new self(\sprintf('%s was changed or removed by someone else since it was loaded. Reload it and apply your change again.', $entity));
    }
}
