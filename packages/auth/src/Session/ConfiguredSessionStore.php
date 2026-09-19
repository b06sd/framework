<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

use Trunk\Auth\Settings\SessionSettings;
use Trunk\Database\Connection\Connection;

/**
 * The default `SessionStore`: picks database, file or array from `auth.session.store` the first time
 * it is used. Bind another `SessionStore` to replace it entirely.
 */
final class ConfiguredSessionStore implements SessionStore
{
    private ?SessionStore $inner = null;

    public function __construct(private readonly SessionSettings $settings, private readonly Connection $connection) {}

    public function read(string $idHash): ?SessionRecord
    {
        return $this->store()->read($idHash);
    }

    public function write(string $idHash, SessionRecord $record): void
    {
        $this->store()->write($idHash, $record);
    }

    public function delete(string $idHash): void
    {
        $this->store()->delete($idHash);
    }

    public function prune(int $lastActivityBefore): int
    {
        return $this->store()->prune($lastActivityBefore);
    }

    private function store(): SessionStore
    {
        return $this->inner ??= match ($this->settings->store) {
            'file' => new FileSessionStore($this->settings->path),
            'array' => new ArraySessionStore(),
            default => new DatabaseSessionStore($this->connection, $this->settings->table),
        };
    }
}
