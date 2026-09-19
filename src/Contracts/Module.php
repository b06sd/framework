<?php

declare(strict_types=1);

namespace Trunk\Contracts;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;

/**
 * A unit of functionality. Implementations must be instantiable without constructor arguments.
 *
 * @api
 */
interface Module
{
    /**
     * Declare bindings. Must not resolve services.
     */
    public function register(ContainerBuilder $builder): void;

    /**
     * Runs after every module has registered.
     */
    public function boot(ContainerInterface $container): void;
}
