<?php

declare(strict_types=1);

namespace Trunk\Container;

use Psr\Container\ContainerInterface;

interface Scopable
{
    /**
     * Opens a scope: scoped services are created once per scope, singletons stay shared with the root.
     *
     * @param array<string, mixed> $instances services that exist only inside this scope (e.g. the current request)
     */
    public function beginScope(array $instances = []): ContainerInterface;
}
