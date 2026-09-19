<?php

declare(strict_types=1);

namespace Trunk\Auth\Token;

use SensitiveParameter;

/**
 * A freshly issued token. `$plainText` is the only time the secret exists outside the client: show it
 * to the user once and never store or log it.
 *
 * @api
 */
final readonly class NewToken
{
    public function __construct(#[SensitiveParameter] public string $plainText, public AccessToken $token) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['plainText' => '[REDACTED]', 'token' => $this->token];
    }
}
