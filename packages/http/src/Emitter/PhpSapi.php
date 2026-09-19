<?php

declare(strict_types=1);

namespace Trunk\Http\Emitter;

final class PhpSapi implements Sapi
{
    public function headersSent(): bool
    {
        return headers_sent();
    }

    public function statusLine(string $protocolVersion, int $status, string $reason): void
    {
        header(rtrim(\sprintf('HTTP/%s %d %s', $protocolVersion, $status, $reason)), true, $status);
    }

    public function header(string $line, bool $replace): void
    {
        header($line, $replace);
    }

    public function write(string $chunk): void
    {
        echo $chunk;
        flush();
    }
}
