<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\Value;
use Trunk\Contracts\Module;
use Trunk\Tests\Fixtures\Services\Logger;
use Trunk\Tests\Fixtures\Services\Mailer;

final class CompilableModule implements Module
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->autowire(Logger::class);
        $builder->service(Mailer::class, Mailer::class, [new Reference(Logger::class), new Value('noreply@trunk.dev')]);
        $builder->alias('mailer', Mailer::class);
    }

    public function boot(ContainerInterface $container): void {}
}
