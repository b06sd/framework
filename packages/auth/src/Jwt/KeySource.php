<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use RuntimeException;

/**
 * Fetches a provider's published key set (JWKS) as JSON text.
 *
 * @internal
 */
interface KeySource
{
    /**
     * @throws RuntimeException when the key set cannot be fetched
     */
    public function fetch(string $url): string;
}
