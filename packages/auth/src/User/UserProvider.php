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

    /**
     * Stores a new password hash and a new session version together, in one write: every session and
     * token issued before ends (a password reset or change).
     */
    public function changePassword(Authenticatable $user, string $hash, string $sessionVersion): void;

    /**
     * Records that `$address` was verified, only while it is still this user's address (an address
     * changed after the link was sent is not verified by it). Returns whether it was recorded.
     */
    public function markEmailVerified(Authenticatable $user, string $address, int $at): bool;

    public function emailVerified(Authenticatable $user): bool;
}
