<?php

declare(strict_types=1);

namespace Trunk\Support;

use Trunk\Foundation\Exception\ConfigurationException;

/**
 * Writes files atomically (temp file + rename) so readers never see a partial artifact.
 */
final class FileWriter
{
    /**
     * @param int|null $mode permissions applied to the file before it becomes visible (e.g. 0o600 for secrets); an existing file keeps its permissions when omitted
     */
    public function write(string $path, string $contents, ?int $mode = null): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $mode ??= is_file($path) ? fileperms($path) & 0o777 : null;

        if (file_put_contents($temporary, $contents) === false || ($mode !== null && !chmod($temporary, $mode)) || !rename($temporary, $path)) {
            @unlink($temporary);

            throw new ConfigurationException(\sprintf('Unable to write "%s".', $path));
        }
    }
}
