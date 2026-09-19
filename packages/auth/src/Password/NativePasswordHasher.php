<?php

declare(strict_types=1);

namespace Trunk\Auth\Password;

use SensitiveParameter;
use Trunk\Auth\Exception\InvalidPasswordException;
use Trunk\Auth\Settings\PasswordSettings;

/**
 * `password_hash` / `password_verify` with argon2id (or bcrypt when configured). Salts are generated
 * by PHP; nothing here is custom cryptography.
 */
final readonly class NativePasswordHasher implements PasswordHasher
{
    public function __construct(private PasswordSettings $settings = new PasswordSettings()) {}

    public function hash(#[SensitiveParameter] string $password): string
    {
        if (mb_strlen($password, '8bit') > $this->settings->maxLength) {
            throw new InvalidPasswordException(\sprintf('The password may be at most %d bytes long.', $this->settings->maxLength));
        }

        if (mb_strlen($password) < $this->settings->minLength) {
            throw new InvalidPasswordException(\sprintf('The password must be at least %d characters long.', $this->settings->minLength));
        }

        return password_hash($password, $this->algorithm(), $this->options());
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        if ($hash === '' || mb_strlen($password, '8bit') > $this->settings->maxLength) {
            return false;
        }

        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options());
    }

    private function algorithm(): string
    {
        return $this->settings->algorithm === 'bcrypt' ? \PASSWORD_BCRYPT : \PASSWORD_ARGON2ID;
    }

    /**
     * @return array<string, int>
     */
    private function options(): array
    {
        return $this->settings->algorithm === 'bcrypt'
            ? ['cost' => 12]
            : ['memory_cost' => $this->settings->memoryCost, 'time_cost' => $this->settings->timeCost, 'threads' => $this->settings->threads];
    }
}
