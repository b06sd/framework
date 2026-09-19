<?php

declare(strict_types=1);

namespace Trunk\Auth\Csrf;

use Trunk\Auth\Session\Session;

/**
 * The CSRF token for the current session. Put `token()` in every form (a hidden `_csrf` field) or send
 * it as the `X-CSRF-Token` header from scripts; `CsrfMiddleware` rejects unsafe requests without it.
 * Each call returns a different value that verifies against the same session secret.
 *
 * @api
 */
final class Csrf
{
    /** @internal wired by the container */
    public function __construct(private readonly Session $session, private readonly CsrfTokens $tokens) {}

    public function token(): string
    {
        return $this->tokens->token($this->session);
    }

    /**
     * Name of the form field `CsrfMiddleware` reads.
     */
    public function field(): string
    {
        return '_csrf';
    }
}
