<?php

declare(strict_types=1);

namespace Trunk\Logging;

use RuntimeException;

/**
 * Writes to a stream such as php://stderr (the right default for containers and workers).
 */
final class StreamHandler implements LogHandler
{
    /** @var resource|null */
    private $stream;

    /**
     * @param resource|string $target a stream resource or a stream URL such as "php://stderr"
     */
    public function __construct(private readonly mixed $target = 'php://stderr')
    {
        $this->stream = \is_resource($target) ? $target : null;
    }

    public function write(string $line): void
    {
        $this->stream ??= \is_string($this->target) ? @fopen($this->target, 'ab') ?: null : null;

        if ($this->stream === null || @fwrite($this->stream, $line) === false) {
            throw new RuntimeException('The log stream could not be written.');
        }
    }
}
