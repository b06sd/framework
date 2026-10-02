<?php

declare(strict_types=1);

namespace Trunk\Auth\Password;

use SensitiveParameter;
use Trunk\Auth\Exception\InvalidPasswordException;
use Trunk\Auth\Link\OneTimeLinks;
use Trunk\Auth\Settings\LinkSettings;
use Trunk\Auth\User\Authenticatable;
use Trunk\Auth\User\UserProvider;

/**
 * "Forgot your password": a one-time link by email, then a new password.
 *
 * The request form must not reveal whether an account exists, and sending an email takes time a
 * stranger can measure. So the form only dispatches a queue job, always, and answers the same way;
 * the job calls `issue()` and sends the link when there is one. `reset()` signs the user out of every
 * session and token (the session version changes with the password).
 *
 * @api
 */
final readonly class PasswordReset
{
    private const string PURPOSE = 'reset';

    /** @internal wired by the container */
    public function __construct(private OneTimeLinks $links, private UserProvider $users, private PasswordHasher $hasher, private LinkSettings $settings) {}

    /**
     * The token to put in the reset link (`https://app.example/reset-password?token=...`), or null when
     * there is no such account, the account has no password, or a link went out less than
     * `auth.links.resend_after` seconds ago. Call it from a queue job, never in the request itself.
     */
    public function issue(string $identifier): ?string
    {
        $user = $this->users->byIdentifier($identifier);

        if ($user === null || $user->authPasswordHash() === '') {
            return null;
        }

        return $this->links->issue(self::PURPOSE, $user, $this->settings->resetTtl);
    }

    /**
     * Whether the link still works, without using it: decide whether to show the new-password form.
     */
    public function isValid(#[SensitiveParameter] string $token): bool
    {
        return $this->links->find(self::PURPOSE, $token) !== null;
    }

    /**
     * Sets the new password and uses the link up. Returns the user (as now stored, ready for
     * `Auth::login()`), or null when the link is unknown, expired, already used, or the password changed
     * since it was sent. Every session and token the user had ends.
     *
     * @throws InvalidPasswordException when the password breaks the length rules; the link still works
     */
    public function reset(#[SensitiveParameter] string $token, #[SensitiveParameter] string $password): ?Authenticatable
    {
        // Hash first: a password the rules refuse does not burn the link, and every attempt costs the same.
        $hash = $this->hasher->hash($password);
        $found = $this->links->consume(self::PURPOSE, $token);

        if ($found === null) {
            return null;
        }

        $user = $found[0];
        $this->users->changePassword($user, $hash, bin2hex(random_bytes(16)));

        return $this->users->byId($user->authId());
    }
}
