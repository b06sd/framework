<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules\Graph;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;

final class CycleBModule implements Module, ModuleDependencies
{
    public function requires(): array
    {
        return [CycleCModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}
}
