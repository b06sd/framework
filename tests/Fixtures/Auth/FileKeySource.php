<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use RuntimeException;
use Trunk\Auth\Jwt\KeySource;

/** The identity provider's key set, read from a file instead of over the network. */
final readonly class FileKeySource implements KeySource
{
    public function __construct(private string $file) {}

    public function fetch(string $url): string
    {
        return (string) (@file_get_contents($this->file) ?: throw new RuntimeException('no key set file'));
    }
}
