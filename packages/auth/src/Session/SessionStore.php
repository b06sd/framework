<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

/**
 * Where sessions live, keyed by the SHA-256 of the session id (64 hex characters). Built-in stores:
 * database (default), file and array. Bind your own with `ContainerBuilder::bind(SessionStore::class, ...)`.
 * Implementations must be safe for concurrent requests: `write` replaces the whole record.
 *
 * @api
 */
interface SessionStore
{
    public function read(string $idHash): ?SessionRecord;

    public function write(string $idHash, SessionRecord $record): void;

    public function delete(string $idHash): void;

    /**
     * Deletes sessions whose last activity is older than the timestamp; returns how many.
     */
    public function prune(int $lastActivityBefore): int;
}
