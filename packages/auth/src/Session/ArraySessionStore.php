<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

/**
 * In-memory sessions for tests and single-process tools. Records go through the same JSON codec as
 * the other stores, so tests see the same restrictions.
 */
final class ArraySessionStore implements SessionStore
{
    /** @var array<string, string> */
    private array $records = [];

    public function read(string $idHash): ?SessionRecord
    {
        return isset($this->records[$idHash]) ? SessionCodec::decode($this->records[$idHash]) : null;
    }

    public function write(string $idHash, SessionRecord $record): void
    {
        $this->records[$idHash] = SessionCodec::encode($record);
    }

    public function delete(string $idHash): void
    {
        unset($this->records[$idHash]);
    }

    public function prune(int $lastActivityBefore): int
    {
        $removed = 0;

        foreach ($this->records as $hash => $json) {
            $record = SessionCodec::decode($json);

            if ($record === null || $record->lastActivity < $lastActivityBefore) {
                unset($this->records[$hash]);
                ++$removed;
            }
        }

        return $removed;
    }
}
