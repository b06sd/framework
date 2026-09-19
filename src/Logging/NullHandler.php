<?php

declare(strict_types=1);

namespace Trunk\Logging;

final readonly class NullHandler implements LogHandler
{
    public function write(string $line): void {}
}
