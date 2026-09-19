<?php

declare(strict_types=1);

namespace Trunk\Auth\Token;

/**
 * What is known about a token except its secret: who it belongs to, what it may do and when it ends.
 *
 * @api
 */
final readonly class AccessToken
{
    /**
     * @param list<string> $abilities what the token grants; `*` grants everything
     */
    public function __construct(
        public string $id,
        public string $userId,
        /** The owner's session version when the token was issued; a different current value ends the token. */
        public string $userVersion,
        public string $name,
        public array $abilities,
        public ?int $expiresAt,
        public ?int $revokedAt,
        public ?int $lastUsedAt,
        public int $createdAt,
    ) {}

    public function isActive(int $now): bool
    {
        return $this->revokedAt === null && ($this->expiresAt === null || $this->expiresAt > $now);
    }
}
