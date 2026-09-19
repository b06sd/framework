<?php

declare(strict_types=1);

namespace Trunk\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Small filesystem helpers. Symlinks are removed, never followed.
 */
final class Directory
{
    public function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);

        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * Restricts a tree to its owner and group: files 0640, directories 0750 (nothing world-readable).
     */
    public function restrict(string $path, int $fileMode = 0o640, int $directoryMode = 0o750): void
    {
        if (is_link($path) || !file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            chmod($path, $fileMode);

            return;
        }

        chmod($path, $directoryMode);

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
            if ($item instanceof SplFileInfo && !$item->isLink()) {
                chmod($item->getPathname(), $item->isDir() ? $directoryMode : $fileMode);
            }
        }
    }
}
