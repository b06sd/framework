<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use Trunk\Auth\User\Authenticatable;

/**
 * Finds the local user a verified token is about. The default follows `jwt.user` in config/jwt.php;
 * bind your own to look users up another way or to create them on first sight:
 * `$builder->bind(JwtUserResolver::class, ExternalUsers::class)`.
 *
 * @api
 */
interface JwtUserResolver
{
    /**
     * The user, or null when there is none (the request is then refused with a 401, unless
     * `jwt.user.match` is `none`).
     */
    public function resolve(Claims $claims): ?Authenticatable;
}
