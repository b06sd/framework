<?php

declare(strict_types=1);

namespace Trunk\Cache\Store;

use JsonException;
use Trunk\Contracts\Clock;
use Trunk\Support\Directory;
use Trunk\Support\FileWriter;

/**
 * Filesystem store. Entries are JSON (never `unserialize`), file names are a hash of the key (a key
 * can never become a path), directories are 0700 and files 0600, writes are atomic, and anything
 * unreadable, tampered with or expired is treated as a miss and removed.
 *
 * File format: first line = expiry timestamp or "-" for none, rest = the JSON value.
 */
final readonly class FileStore implements Store
{
    public function __construct(
        private string $directory,
        private Clock $clock,
        private FileWriter $files = new FileWriter(),
        private Directory $directories = new Directory(),
    ) {}

    public function read(string $key): ?array
    {
        $file = $this->path($key);
        $contents = is_file($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            return null;
        }

        $newline = strpos($contents, "\n");
        $expiry = $newline === false ? '' : substr($contents, 0, $newline);

        if ($newline === false || preg_match('/^(?:-|\d{1,12})$/D', $expiry) !== 1) {
            $this->delete($key);

            return null;
        }

        if ($expiry !== '-' && (int) $expiry <= $this->clock->now()) {
            $this->delete($key);

            return null;
        }

        try {
            return [json_decode(substr($contents, $newline + 1), true, 128, \JSON_THROW_ON_ERROR)];
        } catch (JsonException) {
            $this->delete($key);

            return null;
        }
    }

    public function write(string $key, mixed $value, ?int $expiresAt): bool
    {
        try {
            $json = json_encode($value, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION, 128);
        } catch (JsonException) {
            // Not representable as data (invalid UTF-8, INF/NAN, too deep): refuse rather than corrupt.
            return false;
        }

        $file = $this->path($key);
        $directory = \dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            return false;
        }

        $this->files->write($file, ($expiresAt === null ? '-' : (string) max(0, $expiresAt)) . "\n" . $json, 0o600);

        return true;
    }

    public function delete(string $key): bool
    {
        $file = $this->path($key);

        if (is_file($file)) {
            unlink($file);
        }

        return true;
    }

    /**
     * Removes only the two-hex-digit shard directories this store created.
     */
    public function clear(): bool
    {
        foreach (glob($this->directory . '/*', \GLOB_ONLYDIR) ?: [] as $shard) {
            if (preg_match('/^[0-9a-f]{2}$/D', basename($shard)) === 1 && !is_link($shard)) {
                $this->directories->remove($shard);
            }
        }

        return true;
    }

    private function path(string $key): string
    {
        $hash = hash('sha256', $key);

        return $this->directory . '/' . substr($hash, 0, 2) . '/' . $hash . '.cache';
    }
}
