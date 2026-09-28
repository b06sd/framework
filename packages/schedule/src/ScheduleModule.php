<?php

declare(strict_types=1);

namespace Trunk\Schedule;

use Psr\Container\ContainerInterface;
use Trunk\Console\Process\ProcessLauncher;
use Trunk\Console\Process\ProcOpenLauncher;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Contracts\ModuleDependencies;
use Trunk\Queue\QueueModule;

/**
 * Enables the scheduler. app/Schedule.php is read and run directly by schedule:run
 * (packages/schedule/src/Console), the same way database/seeders/DatabaseSeeder.php is read by
 * db:seed; the only registration needed is the default process launcher schedule:run uses to run a
 * scheduled command in its own process.
 *
 * @api
 */
final class ScheduleModule implements Module, ModuleDependencies
{
    public function requires(): array
    {
        return [QueueModule::class];
    }

    public function after(): array
    {
        return [];
    }

    public function register(ContainerBuilder $builder): void
    {
        $builder->bindDefault(ProcessLauncher::class, ProcOpenLauncher::class);
    }

    public function boot(ContainerInterface $container): void {}
}
