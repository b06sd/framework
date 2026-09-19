<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Trunk\Http\Emitter\Sapi;

final class FakeSapi implements Sapi
{
    /** @var list<string> */
    public array $calls = [];

    public function __construct(private readonly bool $headersSent = false) {}

    public function headersSent(): bool
    {
        return $this->headersSent;
    }

    public function statusLine(string $protocolVersion, int $status, string $reason): void
    {
        $this->calls[] = \sprintf('status:HTTP/%s %d %s', $protocolVersion, $status, $reason);
    }

    public function header(string $line, bool $replace): void
    {
        $this->calls[] = 'header:' . $line . ($replace ? '' : ' (add)');
    }

    public function write(string $chunk): void
    {
        $this->calls[] = 'write:' . $chunk;
    }
}
