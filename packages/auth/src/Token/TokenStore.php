<?php

declare(strict_types=1);

namespace Trunk\Auth\Token;

/**
 * Where issued tokens are kept. The database store is the default; bind your own with
 * `ContainerBuilder::bind(TokenStore::class, ...)`. Only the hash of a secret is ever stored.
 *
 * @api
 */
interface TokenStore
{
    public function create(TokenRecord $record): void;

    public function find(string $id): ?TokenRecord;

    public function touch(string $id, int $now): void;

    public function revoke(string $id, int $now): void;

    /**
     * Revokes every active token of a user (do this when a password changes); returns how many.
     */
    public function revokeAllFor(string $userId, int $now): int;

    /**
     * @return list<AccessToken>
     */
    public function forUser(string $userId): array;

    /**
     * Deletes tokens that expired or were revoked before `$before`; returns how many.
     */
    public function prune(int $before): int;
}
