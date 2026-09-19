<?php

declare(strict_types=1);

namespace Trunk\Auth\User;

/**
 * Whatever can sign in: a database row, an ORM entity, an LDAP entry. The framework only needs these
 * three facts about it.
 *
 * @api
 */
interface Authenticatable
{
    /** Stable, unique id (stored in the session and in tokens). */
    public function authId(): string;

    /** The stored password hash; empty when the account cannot use a password. */
    public function authPasswordHash(): string;

    /**
     * Changes whenever sessions and tokens issued earlier must stop working (a password change, an
     * account lock). Sessions remember the value they were created with and end when it differs.
     */
    public function authSessionVersion(): string;
}
