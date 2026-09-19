<?php

declare(strict_types=1);

namespace Trunk\Auth\User;

/**
 * Finds users. The database provider is the default; write your own for an ORM entity or another
 * source and bind it with `ContainerBuilder::bind(UserProvider::class, ...)`.
 *
 * @api
 */
interface UserProvider
{
    public function byId(string $id): ?Authenticatable;

    /**
     * Looks a user up by what they type to sign in (an email address or a username). Return null when
     * there is none; do not throw, and do not distinguish "no such user" in any visible way.
     */
    public function byIdentifier(string $identifier): ?Authenticatable;

    /**
     * Stores a fresh hash after a successful login with an outdated one. It must not change the
     * session version.
     */
    public function updatePasswordHash(Authenticatable $user, string $hash): void;
}
