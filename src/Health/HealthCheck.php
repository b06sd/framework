<?php

declare(strict_types=1);

namespace Trunk\Health;

/**
 * A readiness probe for one dependency (database, cache, queue...). Register implementations with
 * the tag `trunk.health_check`. check() should be quick and side-effect free; throw or return a
 * `down` result on failure. Details are for logs only and are never sent to a client in production.
 *
 * @api
 */
interface HealthCheck
{
    public function name(): string;

    public function check(): HealthResult;
}
