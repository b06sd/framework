<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Trunk\Auth\Authorization\Ability;
use Trunk\Auth\User\Authenticatable;
use Trunk\Auth\User\DatabaseUser;

final class ManageUsers implements Ability
{
    public function name(): string
    {
        return 'manage-users';
    }

    public function allows(Authenticatable $user): bool
    {
        return $user instanceof DatabaseUser && ($user->attributes['email'] ?? null) === 'admin@example.com';
    }
}
