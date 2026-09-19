<?php

declare(strict_types=1);

namespace Trunk\Logging;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Appends to `<directory>/trunk-YYYY-MM-DD.log` (UTC day), taking an exclusive lock per write. The
 * file name is built by this class only, so no log content can influence the path. Rotation and
 * retention are left to the platform (logrotate, the container runtime).
 */
final readonly class FileHandler implements LogHandler
{
    /**
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(private string $directory, private ?Closure $clock = null) {}

    public function write(string $line): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The log directory could not be created.');
        }

        $day = ($this->clock !== null ? ($this->clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $path = rtrim($this->directory, '/') . '/trunk-' . $day . '.log';

        if (!file_exists($path) && @touch($path)) {
            @chmod($path, 0o640);
        }

        if (@file_put_contents($path, $line, \FILE_APPEND | \LOCK_EX) === false) {
            throw new RuntimeException('The log file could not be written.');
        }
    }
}
