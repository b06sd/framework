<?php

declare(strict_types=1);

namespace Trunk\Auth\Authorization;

use Trunk\Auth\User\Authenticatable;

/**
 * A permission that is not about one object ("manage-users", "view-reports"). Register it as a
 * service tagged `auth.ability`. Anything not registered is denied.
 *
 * @api
 */
interface Ability
{
    /** The name passed to `Gate::allows()`. */
    public function name(): string;

    public function allows(Authenticatable $user): bool;
}
