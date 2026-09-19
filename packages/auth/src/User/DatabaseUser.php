<?php

declare(strict_types=1);

namespace Trunk\Auth\User;

use SensitiveParameter;

/**
 * A row of the users table. The password hash is available through the Authenticatable contract only:
 * it is not in `$attributes`, and it never shows in a dump.
 *
 * @api
 */
final readonly class DatabaseUser implements Authenticatable
{
    /**
     * @param array<string, scalar|null> $attributes every column except the password
     */
    public function __construct(
        private string $id,
        #[SensitiveParameter]
        private string $passwordHash,
        private string $sessionVersion,
        public array $attributes = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['id' => $this->id, 'attributes' => $this->attributes];
    }

    public function authId(): string
    {
        return $this->id;
    }

    public function authPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function authSessionVersion(): string
    {
        return $this->sessionVersion;
    }
}
