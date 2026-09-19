<?php

declare(strict_types=1);

namespace Trunk\Auth\Authorization;

use LogicException;
use Trunk\Auth\Auth;
use Trunk\Error\ErrorCode;
use Trunk\Http\Exception\HttpException;

/**
 * Asks "may the current user do this?". It denies by default: nobody signed in, an unknown ability,
 * an object no policy handles and a bearer token that does not list the ability are all refusals.
 * Errors thrown by a policy are not swallowed (they fail the request, they never allow it).
 *
 * @api
 */
final class Gate
{
    /** @var array<class-string, Policy> */
    private array $policies = [];

    /** @var array<string, Ability> */
    private array $abilities = [];

    /**
     * @param iterable<mixed> $policies  services tagged `auth.policy`
     * @param iterable<mixed> $abilities services tagged `auth.ability`
     *
     * @internal wired by the container
     */
    public function __construct(private readonly Auth $auth, iterable $policies = [], iterable $abilities = [])
    {
        foreach ($policies as $policy) {
            if (!$policy instanceof Policy) {
                throw new LogicException(\sprintf('A service tagged "auth.policy" must implement %s, %s given.', Policy::class, get_debug_type($policy)));
            }

            foreach ($policy->handles() as $class) {
                $this->policies[$class] ??= $policy;
            }
        }

        foreach ($abilities as $ability) {
            if (!$ability instanceof Ability) {
                throw new LogicException(\sprintf('A service tagged "auth.ability" must implement %s, %s given.', Ability::class, get_debug_type($ability)));
            }

            if (isset($this->abilities[$ability->name()])) {
                throw new LogicException(\sprintf('The ability "%s" is defined by both %s and %s; ability names must be unique.', $ability->name(), $this->abilities[$ability->name()]::class, $ability::class));
            }

            $this->abilities[$ability->name()] = $ability;
        }
    }

    /**
     * @param object|null $subject the object the ability applies to; null for a general ability
     */
    public function allows(string $ability, ?object $subject = null): bool
    {
        $user = $this->auth->user();

        if ($user === null || ($this->auth->viaToken() && !$this->auth->tokenCan($ability))) {
            return false;
        }

        if ($subject === null) {
            return ($this->abilities[$ability] ?? null)?->allows($user) === true;
        }

        return $this->policyFor($subject)?->can($ability, $user, $subject) === true;
    }

    public function denies(string $ability, ?object $subject = null): bool
    {
        return !$this->allows($ability, $subject);
    }

    /**
     * @throws HttpException 401 when nobody is signed in, otherwise 403
     */
    public function authorize(string $ability, ?object $subject = null): void
    {
        if ($this->allows($ability, $subject)) {
            return;
        }

        throw $this->auth->check() ? new HttpException(403, 'You may not do this.', [], null, ErrorCode::AccessDenied->value) : new HttpException(401, 'Authentication is required.');
    }

    private function policyFor(object $subject): ?Policy
    {
        foreach ([$subject::class, ...array_values(class_parents($subject) ?: []), ...array_values(class_implements($subject) ?: [])] as $class) {
            if (isset($this->policies[$class])) {
                return $this->policies[$class];
            }
        }

        return null;
    }
}
