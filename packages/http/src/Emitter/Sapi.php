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

    public function write(string $chunk): void;
}
