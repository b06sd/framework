<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Modules;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;

/**
 * Unlike FailingContributorModule (whose plan() throws), this throws from the constructor itself,
 * the failure mode ModuleInstances exists to attribute clearly.
 */
final class ThrowingConstructorModule implements Module
{
    public function __construct()
    {
        throw new RuntimeException('the module is unhappy');
    }

    public function register(ContainerBuilder $builder): void {}

    public function boot(ContainerInterface $container): void {}
}
