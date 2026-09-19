<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules\Graph;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;

final class BaseModule implements Module
{
    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}
}
