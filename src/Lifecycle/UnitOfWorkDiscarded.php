<?php

declare(strict_types=1);

namespace Trunk\Lifecycle;

use LogicException;

/**
 * Thrown by a LifecycleAware reset that had to throw away work the unit of work did, such as a
 * database transaction left open and rolled back. A request that ends this way is answered with a
 * 500, never with the success its handler returned, because what it reported as saved was not.
 *
 * @api
 */
final class UnitOfWorkDiscarded extends LogicException {}
