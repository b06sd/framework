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

    /**
     * A locking query found a row that changed after this unit of work had already loaded it.
     */
    public static function lockedTooLate(string $entity): self
    {
        return new self(\sprintf('%s was loaded before it was locked, and the row has changed since. Load it with lockForUpdate() before anything else reads it in this transaction.', $entity));
    }
}
