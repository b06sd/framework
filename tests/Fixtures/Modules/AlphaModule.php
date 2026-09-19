<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use ArrayObject;
use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;

final class AlphaModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->instance('log', new ArrayObject());
    }

    public function boot(ContainerInterface $container): void
    {
        $log = $container->get('log');

        if ($log instanceof ArrayObject) {
            $log->append('alpha');
        }
    }
}
