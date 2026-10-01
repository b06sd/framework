<?php

declare(strict_types=1);

namespace Trunk\Http\Emitter;

/**
 * The only place HTTP output leaves the process. PHP's SAPI is one implementation;
 * long-running runtimes can provide their own.
 */
interface Sapi
{
    public function headersSent(): bool;

    public function statusLine(string $protocolVersion, int $status, string $reason): void;

    public function header(string $line, bool $replace): void;

    /**
     * Hands a body chunk to the output layer. It need not leave the process yet; see flush().
     */
    public function write(string $chunk): void;

    /**
     * Pushes what has been written out to the client now (used only for streamed bodies).
     */
    public function flush(): void;

    /**
     * Whether the body reaches the client exactly as written. False when the runtime compresses or
     * otherwise rewrites output (PHP's zlib.output_compression, an ob_gzhandler buffer), in which case
     * a Content-Length computed from the body would be wrong, and in PHP's case would quietly turn
     * the compression off.
     */
    public function bodyPassesThrough(): bool;
}
