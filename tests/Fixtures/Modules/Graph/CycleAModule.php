<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules\Graph;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;

final class CycleAModule implements Module, ModuleDependencies
{
    public function requires(): array
    {
        return [CycleBModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}
}
