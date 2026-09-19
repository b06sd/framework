<?php

declare(strict_types=1);

namespace Trunk\Auth\Authorization;

use Trunk\Auth\User\Authenticatable;

/**
 * Decides what users may do to one kind of object. Register it as a service tagged `auth.policy`
 * (`$builder->autowire(PostPolicy::class); $builder->tag('auth.policy', PostPolicy::class);`) and
 * list the classes it decides for in `handles()`. The `Gate` calls `can()` with the ability name
 * ("update", "delete"); write it as a plain `match`. Anything the policy does not allow is denied.
 *
 * @api
 */
interface Policy
{
    /**
     * @return list<class-string> the classes (or interfaces) whose objects this policy decides for
     */
    public function handles(): array;

    public function can(string $ability, Authenticatable $user, object $subject): bool;
}
