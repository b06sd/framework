<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Auth;

use Psr\Container\ContainerInterface;
use stdClass;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;

/**
 * Tags classes that are not policies or abilities, to prove the build catches it.
 */
final class BadPolicyModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(stdClass::class);
        $builder->tag('auth.policy', stdClass::class);
        $builder->tag('auth.ability', stdClass::class);
    }

    public function boot(ContainerInterface $container): void {}
}
