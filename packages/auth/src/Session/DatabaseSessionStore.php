<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

use Trunk\Database\Connection\Connection;
use Trunk\Database\Exception\QueryException;

/**
 * Sessions in a table created by `trunk auth:table`. The payload holds only JSON; the key is the hash
 * of the session id. Writes are an update followed by an insert, so two requests racing to create the
 * same session cannot fail each other.
 */
final readonly class DatabaseSessionStore implements SessionStore
{
    public function __construct(private Connection $connection, private string $table) {}

    public function read(string $idHash): ?SessionRecord
    {
        $row = $this->connection->table($this->table)->where('id_hash', '=', $idHash)->first();
        $payload = $row['payload'] ?? null;

        return \is_string($payload) ? SessionCodec::decode($payload) : null;
    }

    public function write(string $idHash, SessionRecord $record): void
    {
        $payload = SessionCodec::encode($record);
        $values = ['payload' => $payload, 'last_activity' => $record->lastActivity];
        $rows = $this->connection->table($this->table)->where('id_hash', '=', $idHash)->update($values);

        if ($rows > 0) {
            return;
        }

        try {
            $this->connection->table($this->table)->insert(['id_hash' => $idHash, 'payload' => $payload, 'created_at' => $record->createdAt, 'last_activity' => $record->lastActivity]);
        } catch (QueryException $e) {
            // MySQL reports 0 changed rows for an identical update, and a parallel request may have inserted first.
            if (!$this->connection->table($this->table)->where('id_hash', '=', $idHash)->exists()) {
                throw $e;
            }

            $this->connection->table($this->table)->where('id_hash', '=', $idHash)->update($values);
        }
    }

    public function delete(string $idHash): void
    {
        $this->connection->table($this->table)->where('id_hash', '=', $idHash)->delete();
    }

    public function prune(int $lastActivityBefore): int
    {
        return $this->connection->table($this->table)->where('last_activity', '<', $lastActivityBefore)->delete();
    }
}
