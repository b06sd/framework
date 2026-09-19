<?php

declare(strict_types=1);

namespace Trunk\Contracts;

/**
 * Optional: a module that needs other modules says so. The manifest (trunk.php) stays the single,
 * readable source of order; Trunk checks it against these declarations and reports what to fix
 * instead of silently reordering. Modules that do not implement this are unaffected.
 *
 * @api
 */
interface ModuleDependencies
{
    /**
     * Modules that must be listed in trunk.php, above this one.
     *
     * @return list<class-string<Module>>
     */
    public function requires(): array;

    /**
     * Modules that only need to come earlier when they are present (an optional integration).
     *
     * @return list<class-string<Module>>
     */
    public function after(): array;
}
