<?php

declare(strict_types=1);

namespace Trunk\Orm\UnitOfWork;

/**
 * Told what every flush wrote: for an audit trail, a search index, cache invalidation, or messages
 * to other systems. Tag the service `orm.change_listener`.
 *
 * It runs inside the flush's transaction, after every write and before the commit, so what it writes
 * through the database connection commits or rolls back together with the changes; an exception
 * from it rolls the whole flush back. Write with the connection (its query builder), not with
 * EntityManager::flush(), which refuses to run again while listeners are being told.
 *
 * @api
 */
interface ChangeListener
{
    public function changed(Changes $changes): void;
}
