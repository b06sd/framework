<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Scopable;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Runtime;

/**
 * Builds the same registrations as a development container and as a compiled container, so tests
 * can assert that both behave identically.
 */
final class ContainerModes
{
    /**
     * @param Closure(ContainerBuilder): void $define
     * @param array<string, mixed>           $configuration
     * @param list<string>                   $roots
     *
     * @return array<string, ContainerInterface&Scopable>
     */
    public function both(Closure $define, array $configuration = [], array $roots = []): array
    {
        $config = new Configuration($configuration);
        $runtime = new Runtime(Environment::Production, false, sys_get_temp_dir());

        $development = new ContainerBuilder();
        $development->instance(Configuration::class, $config);
        $development->instance(Runtime::class, $runtime);
        $define($development);

        $builder = new ContainerBuilder();
        $define($builder);
        $loader = new CompiledContainerLoader();
        $name = $loader->uniqueName();
        $source = new ContainerCompiler()->compile($builder, [], [Configuration::class, Scopable::class, Runtime::class], $name, $roots, [ServerRequestInterface::class]);
        $class = $loader->load($source, $name);

        return ['development' => $development->build(), 'compiled' => new $class([Configuration::class => $config, Runtime::class => $runtime])];
    }

    /**
     * @param Closure(ContainerBuilder): void $define
     * @param list<string>                    $roots
     * @param list<string>                    $modules
     */
    public function source(Closure $define, array $roots = [], array $modules = []): string
    {
        $builder = new ContainerBuilder();
        $define($builder);

        return new ContainerCompiler()->compile($builder, $modules, [Configuration::class], 'AppContainer', $roots, [ServerRequestInterface::class]);
    }
}
