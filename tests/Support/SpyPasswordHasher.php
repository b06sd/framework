<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use SensitiveParameter;
use Trunk\Auth\Password\PasswordHasher;

/**
 * Counts how much hashing work a code path does, without doing any.
 */
final class SpyPasswordHasher implements PasswordHasher
{
    public int $hashes = 0;

    public int $verifies = 0;

    public function hash(#[SensitiveParameter] string $password): string
    {
        ++$this->hashes;

        return 'hash:' . $password;
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        ++$this->verifies;

        return $hash === 'hash:' . $password;
    }

    public function needsRehash(string $hash): bool
    {
        return false;
    }
}
