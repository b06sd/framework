<?php

declare(strict_types=1);

namespace Trunk\Lifecycle;

/**
 * Implemented by services that hold state for the life of a process (a request context, a query
 * log, an in-memory cache). Long-running workers call reset() between units of work so nothing
 * accumulates. Tag the service `trunk.lifecycle` to have it reset automatically.
 *
 * @api
 */
interface LifecycleAware
{
    public function reset(): void;
}
