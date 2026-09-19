<?php

declare(strict_types=1);

namespace Trunk\Compiler;

use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Scopable;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Manifest\ManifestCompiler;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Logging\RequestContext;
use Trunk\Support\FileWriter;

/**
 * Build-time only. Registers the manifest's modules, compiles the resulting graph and writes
 * `container.php` and `modules.php` into the build directory.
 */
final readonly class ArtifactWriter
{
    /** Ids the Application supplies at runtime rather than compiling. */
    public const array EXTERNAL_IDS = [Runtime::class, Configuration::class, Scopable::class];

    /** Ids the core provides per scope; modules declare their own with ContainerBuilder::providedInScope(). */
    public const array SCOPED_IDS = [RequestContext::class];

    public function __construct(
        private ContainerCompiler $compiler = new ContainerCompiler(),
        private ManifestCompiler $manifests = new ManifestCompiler(),
        private FileWriter $files = new FileWriter(),
    ) {}

    /**
     * Registers the modules and validates/compiles the graph without touching the filesystem.
     *
     * @param list<string> $roots ids the application resolves directly (controllers, middleware); unbound concrete ones are wired automatically
     *
     * @throws CompilationException
     */
    public function plan(ModuleManifest $manifest, string $className = 'AppContainer', array $roots = []): ContainerPlan
    {
        $builder = new ContainerBuilder();

        foreach ($manifest->modules as $class) {
            $module = new $class();
            $module->register($builder);
        }

        return $this->compiler->plan($builder, $manifest->modules, self::EXTERNAL_IDS, $className, $roots, self::SCOPED_IDS);
    }

    /**
     * @throws CompilationException
     */
    public function write(ModuleManifest $manifest, string $directory, string $className = 'AppContainer'): void
    {
        $this->writePlan($this->plan($manifest, $className), $manifest, $directory);
    }

    /**
     * @throws CompilationException
     */
    public function writePlan(ContainerPlan $plan, ModuleManifest $manifest, string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new CompilationException([\sprintf('Unable to create build directory "%s".', $directory)]);
        }

        $this->files->write($directory . '/container.php', $plan->source);
        $this->manifests->write($manifest->modules, $directory . '/modules.php');
    }
}
