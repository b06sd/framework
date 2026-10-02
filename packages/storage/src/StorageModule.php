<?php

declare(strict_types=1);

namespace Trunk\Storage;

use Psr\Container\ContainerInterface;
use Trunk\Compiler\Build\BuildContext;
use Trunk\Compiler\Build\BuildContribution;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Contracts\BuildContributor;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;

/**
 * File storage over Flysystem: inject `Storage`. Disks come from config/storage.php; `trunk build`
 * checks every disk, and that the default one is complete.
 *
 * @api
 */
final class StorageModule implements Module, BuildContributor
{
    public function register(ContainerBuilder $builder): void
    {
        $builder->service(DiskFactory::class, DiskFactory::class);
        $builder->service(Storage::class, Storage::class, [new Reference(Configuration::class), new Reference(DiskFactory::class)]);
    }

    public function boot(ContainerInterface $container): void {}

    public function plan(BuildContext $context): BuildContribution
    {
        $configuration = $context->configuration;
        $default = $configuration->has('storage.default') ? $configuration->get('storage.default') : null;
        $disks = $configuration->has('storage.disks') ? $configuration->get('storage.disks') : null;
        $problems = [];

        if (!\is_array($disks) || $disks === []) {
            $problems[] = 'storage.disks must name at least one disk.';
        } elseif (!\is_string($default) || !\is_array($disks[$default] ?? null)) {
            $problems[] = 'storage.default (STORAGE_DISK) must be one of the disks in storage.disks.';
        } else {
            foreach ($disks as $name => $settings) {
                $problems = [...$problems, ...(\is_array($settings) ? DiskFactory::problems((string) $name, $settings, $name === $default) : ['storage.disks.' . $name . ' must be an array of settings.'])];
            }
        }

        if ($problems !== []) {
            throw new CompilationException(array_map(static fn(string $p): string => 'config/storage.php: ' . $p, $problems));
        }

        return new BuildContribution();
    }
}
