<?php

declare(strict_types=1);

namespace Trunk\Contracts;

/**
 * The current time as a Unix timestamp in seconds. Inject it wherever expiry, retries or timeouts
 * depend on the time, so tests can control it. Bind your own with `ContainerBuilder::bind()`;
 * capabilities that need a clock provide the system clock as a default.
 *
 * @api
 */
interface Clock
{
    public function now(): int;
}
