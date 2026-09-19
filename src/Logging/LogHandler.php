<?php

declare(strict_types=1);

namespace Trunk\Logging;

/**
 * Where formatted log lines go. write() may throw; the logger contains that.
 */
interface LogHandler
{
    public function write(string $line): void;
}
