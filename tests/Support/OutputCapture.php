<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use RuntimeException;
use Trunk\Console\Output\Output;

/**
 * An Output writing to in-memory streams, so tests can read what a command printed.
 */
final class OutputCapture
{
    public readonly Output $output;
    /** @var resource */
    private mixed $out;

    /** @var resource */
    private mixed $error;

    public function __construct()
    {
        $out = fopen('php://memory', 'w+');
        $error = fopen('php://memory', 'w+');
        if ($out === false || $error === false) {
            throw new RuntimeException('Unable to open memory streams.');
        }

        $this->out = $out;
        $this->error = $error;
        $this->output = new Output($out, $error);
    }

    public function stdout(): string
    {
        rewind($this->out);

        return (string) stream_get_contents($this->out);
    }

    public function stderr(): string
    {
        rewind($this->error);

        return (string) stream_get_contents($this->error);
    }
}
