<?php

declare(strict_types=1);

namespace Trunk\Http\Security;

use Trunk\Foundation\Configuration;

/**
 * Builds the `SecurityHeaders` service from config/http.php for `SecurityHeadersMiddleware`.
 */
final readonly class SecurityHeadersFactory
{
    public function create(Configuration $configuration): SecurityHeaders
    {
        return SecurityHeaders::configured($configuration);
    }
}
