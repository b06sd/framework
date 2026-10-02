<?php

declare(strict_types=1);

namespace Trunk\Auth\Verification;

use SensitiveParameter;
use Trunk\Auth\Link\OneTimeLinks;
use Trunk\Auth\Settings\LinkSettings;
use Trunk\Auth\User\Authenticatable;
use Trunk\Auth\User\UserProvider;
use Trunk\Contracts\Clock;

/**
 * Proves a user owns their email address: send a one-time link to it, and the click records the
 * address as verified. The link is bound to the address it was sent to: if the account's address
 * changed in the meantime, the old link verifies nothing. (When your application changes a user's
 * address, clear the verified column so the new one is checked too.)
 *
 * @api
 */
final readonly class EmailVerification
{
    private const string PURPOSE = 'verify';

    /** @internal wired by the container */
    public function __construct(private OneTimeLinks $links, private UserProvider $users, private LinkSettings $settings, private Clock $clock) {}

    /**
     * The token for a verification link sent to `$address` (the address the user signs in with), or
     * null when one went out less than `auth.links.resend_after` seconds ago.
     */
    public function issue(Authenticatable $user, string $address): ?string
    {
        return $this->links->issue(self::PURPOSE, $user, $this->settings->verifyTtl, $address);
    }

    /**
     * Records the address as verified and returns the user, or null when the link is unknown,
     * expired, already used, or the address is no longer the user's.
     */
    public function verify(#[SensitiveParameter] string $token): ?Authenticatable
    {
        $found = $this->links->consume(self::PURPOSE, $token);

        if ($found === null || $found[1] === null) {
            return null;
        }

        return $this->users->markEmailVerified($found[0], $found[1], $this->clock->now()) ? $found[0] : null;
    }

    public function isVerified(Authenticatable $user): bool
    {
        return $this->users->emailVerified($user);
    }
}
