<?php

declare(strict_types=1);

namespace Trunk\Logging;

interface LogFormatter
{
    public function format(LogRecord $record): string;
}
