<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use ArrayObject;
use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;

final class BetaModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->instance('beta', true);
    }

    public function boot(ContainerInterface $container): void
    {
        $log = $container->get('log');

        if ($log instanceof ArrayObject) {
            $log->append('beta');
        }
    }
}
