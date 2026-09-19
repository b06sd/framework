<?php

declare(strict_types=1);

namespace Trunk\Application;

use Psr\Container\ContainerInterface;
use Trunk\Application\Exception\LifecycleException;
use Trunk\Container\CompiledContainer;
use Trunk\Container\ContainerBuilder;
use Trunk\Contracts\Module;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Manifest\ModuleGraph;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;

/**
 * Owns the lifecycle: Created -> register() -> Registered -> boot() -> Booted.
 * Each phase runs exactly once. There is no static accessor.
 */
final class Application
{
    private Phase $phase = Phase::Created;

    /** @var list<Module> */
    private array $modules = [];

    private ?ContainerInterface $container = null;

    public function __construct(
        private readonly Runtime $runtime,
        private readonly Configuration $configuration,
        private readonly ModuleManifest $manifest,
        /** @var class-string<CompiledContainer>|null generated container to use instead of running module register() */
        private readonly ?string $compiledContainer = null,
    ) {}

    public function phase(): Phase
    {
        return $this->phase;
    }

    public function register(): void
    {
        $this->expect(Phase::Created, __FUNCTION__);

        if ($this->compiledContainer !== null) {
            $this->useCompiledContainer($this->compiledContainer);
            $this->phase = Phase::Registered;

            return;
        }

        $builder = new ContainerBuilder();
        $builder->instance(Runtime::class, $this->runtime);
        $builder->instance(Configuration::class, $this->configuration);

        $problems = new ModuleGraph()->errors($this->manifest->modules);

        if ($problems !== []) {
            throw new ConfigurationException("trunk.php lists its modules incorrectly:\n - " . implode("\n - ", $problems));
        }

        $modules = $this->instantiateModules();

        foreach ($modules as $module) {
            $module->register($builder);
        }

        $this->modules = $modules;
        $this->container = $builder->build();
        $this->phase = Phase::Registered;
    }

    public function boot(): void
    {
        $this->expect(Phase::Registered, __FUNCTION__);

        $container = $this->container();

        foreach ($this->modules as $module) {
            $module->boot($container);
        }

        $this->phase = Phase::Booted;
    }

    public function container(): ContainerInterface
    {
        return $this->container ?? throw new LifecycleException('The container is not available before register().');
    }

    /**
     * @param class-string<CompiledContainer> $class
     */
    private function useCompiledContainer(string $class): void
    {
        if ($class::MODULES !== $this->manifest->modules) {
            throw new LifecycleException('The compiled container was built from a different module set; rebuild it.');
        }

        $this->modules = $this->instantiateModules();
        $this->container = new $class([
            Runtime::class => $this->runtime,
            Configuration::class => $this->configuration,
        ]);
    }

    /**
     * @return list<Module>
     */
    private function instantiateModules(): array
    {
        $modules = [];

        foreach ($this->manifest->modules as $class) {
            $modules[] = new $class();
        }

        return $modules;
    }

    private function expect(Phase $required, string $action): void
    {
        if ($this->phase !== $required) {
            throw new LifecycleException(\sprintf(
                'Cannot %s() while the application is in the %s phase.',
                $action,
                $this->phase->name,
            ));
        }
    }
}
