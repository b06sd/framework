<?php

declare(strict_types=1);

namespace Trunk\Auth\Password;

use SensitiveParameter;
use Trunk\Auth\Exception\InvalidPasswordException;

/**
 * Hashes and verifies passwords. Bind your own implementation to change the algorithm; the default
 * uses PHP's `password_hash` (argon2id) with the parameters in config/auth.php.
 *
 * @api
 */
interface PasswordHasher
{
    /**
     * @throws InvalidPasswordException when the password breaks the length rules
     */
    public function hash(#[SensitiveParameter] string $password): string;

    /**
     * Constant-time check. A password over the maximum length is simply not a match.
     */
    public function verify(#[SensitiveParameter] string $password, string $hash): bool;

    /**
     * True when the hash was made with weaker parameters than the current settings, so the caller can
     * store a fresh hash after a successful login.
     */
    public function needsRehash(string $hash): bool;
}
