<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use RuntimeException;
use Trunk\Auth\Authorization\Policy;
use Trunk\Auth\User\Authenticatable;

final class PostPolicy implements Policy
{
    public function handles(): array
    {
        return [Content::class];
    }

    public function can(string $ability, Authenticatable $user, object $subject): bool
    {
        if (!$subject instanceof Content) {
            return false;
        }

        return match ($ability) {
            'view' => true,
            'update', 'delete' => $subject->ownerId === $user->authId(),
            'explode' => throw new RuntimeException('policy bug'),
            default => false,
        };
    }
}
