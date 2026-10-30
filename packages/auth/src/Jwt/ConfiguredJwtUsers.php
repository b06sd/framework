<?php

declare(strict_types=1);

namespace Trunk\Auth\Jwt;

use Trunk\Auth\User\Authenticatable;
use Trunk\Auth\User\UserProvider;

/**
 * Finds the user as `jwt.user` says: the claim (`sub` by default) holds your users' id (`id`) or their
 * identifier (`identifier`, usually the email), or there is no local user (`none`). An `email` claim
 * is trusted only with `"email_verified": true`, so nobody can sign in as an address they do not own.
 *
 * @internal
 */
final readonly class ConfiguredJwtUsers implements JwtUserResolver
{
    public function __construct(private JwtSettings $settings, private UserProvider $users) {}

    public function resolve(Claims $claims): ?Authenticatable
    {
        $value = $this->settings->userClaim === 'sub' ? $claims->subject : $claims->get($this->settings->userClaim);

        if (!\is_string($value) || $value === '' || $this->settings->userMatch === 'none') {
            return null;
        }

        if ($this->settings->userClaim === 'email' && $claims->get('email_verified') !== true) {
            return null;
        }

        return $this->settings->userMatch === 'identifier' ? $this->users->byIdentifier($value) : $this->users->byId($value);
    }
}
