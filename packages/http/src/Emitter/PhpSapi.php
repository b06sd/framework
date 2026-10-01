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
    }

    public function flush(): void
    {
        flush();
    }

    public function bodyPassesThrough(): bool
    {
        if (filter_var(\ini_get('zlib.output_compression'), \FILTER_VALIDATE_BOOLEAN) || (int) \ini_get('zlib.output_compression') > 0) {
            return false;
        }

        // Any buffer other than PHP's own pass-through one may rewrite what is written.
        foreach (ob_list_handlers() as $handler) {
            if ($handler !== 'default output handler') {
                return false;
            }
        }

        return true;
    }
}
