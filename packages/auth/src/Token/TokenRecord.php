<?php

declare(strict_types=1);

namespace Trunk\Auth\Token;

use SensitiveParameter;

/**
 * A token as a store keeps it: the public facts and the SHA-256 of the secret.
 *
 * @api
 */
final readonly class TokenRecord
{
    public function __construct(public AccessToken $token, #[SensitiveParameter] public string $secretHash) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['token' => $this->token, 'secretHash' => '[REDACTED]'];
    }
}
