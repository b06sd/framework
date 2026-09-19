<?php

declare(strict_types=1);

namespace Trunk\Auth\Session;

use InvalidArgumentException;
use RuntimeException;
use Trunk\Support\FileWriter;

/**
 * One file per session in a private directory (0700, files 0600). The file name is the id's hash, so
 * no request input ever reaches the file system, and writes are atomic.
 */
final class FileSessionStore implements SessionStore
{
    public function __construct(private readonly string $directory, private readonly FileWriter $files = new FileWriter()) {}

    public function read(string $idHash): ?SessionRecord
    {
        $path = $this->path($idHash);

        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);

        return $json === false ? null : SessionCodec::decode($json);
    }

    public function write(string $idHash, SessionRecord $record): void
    {
        $path = $this->path($idHash);

        if (!is_dir($this->directory) && !mkdir($this->directory, 0o700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The session directory could not be created.');
        }

        $this->files->write($path, SessionCodec::encode($record), 0o600);
    }

    public function delete(string $idHash): void
    {
        $path = $this->path($idHash);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function prune(int $lastActivityBefore): int
    {
        $removed = 0;

        foreach (glob($this->directory . '/*.session') ?: [] as $file) {
            $json = file_get_contents($file);
            $record = $json === false ? null : SessionCodec::decode($json);

            if ($record === null || $record->lastActivity < $lastActivityBefore) {
                unlink($file);
                ++$removed;
            }
        }

        return $removed;
    }

    private function path(string $idHash): string
    {
        if (!SessionId::isValidHash($idHash)) {
            throw new InvalidArgumentException('A session store key is the 64 hex characters of a SHA-256.');
        }

        return $this->directory . '/' . $idHash . '.session';
    }
}
