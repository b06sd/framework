<?php

declare(strict_types=1);

namespace Trunk\Tests\Support;

use LogicException;
use Trunk\Application\Application;
use Trunk\Container\CompiledContainer;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Http\Build\HttpArtifactBuilder;
use Trunk\Http\Emitter\ResponseEmitter;
use Trunk\Http\HttpModule;
use Trunk\Http\Kernel\HttpKernel;
use Trunk\Http\Kernel\HttpKernelFactory;
use Trunk\Tests\Fixtures\Modules\WebModule;

/**
 * Builds a kernel the way an application would: either from a compiled build directory or
 * straight from modules in development mode.
 */
final class KernelHarness
{
    public string $directory;

    private ?string $compiledClass = null;

    /**
     * @param list<class-string<\Trunk\Contracts\Module>>|null $modules   defaults to HttpModule + WebModule
     * @param array<string, mixed>                                $configuration
     */
    public function __construct(
        public readonly FakeSapi $sapi = new FakeSapi(),
        private readonly ?array $modules = null,
        private readonly array $configuration = [],
    ) {
        $this->directory = sys_get_temp_dir() . '/trunk-kernel-' . bin2hex(random_bytes(4));
    }

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest($this->modules ?? [HttpModule::class, WebModule::class]);
    }

    public function compiled(Environment $environment = Environment::Production, bool $debug = false): HttpKernel
    {
        $manifest = $this->manifest();

        if ($this->compiledClass === null) {
            $name = 'K' . bin2hex(random_bytes(4));
            new HttpArtifactBuilder()->build($manifest, $this->directory, $name);
            require $this->directory . '/container.php';
            $this->compiledClass = 'Trunk\\Compiled\\' . $name;
        }

        $class = $this->compiledClass;

        if (!is_subclass_of($class, CompiledContainer::class)) {
            throw new LogicException('The container was not generated.');
        }

        $app = new Application(new Runtime($environment, $debug, $this->directory), new Configuration($this->configuration), $manifest, $class);
        $app->register();
        $app->boot();

        return new HttpKernelFactory(new ResponseEmitter($this->sapi))->compiled($app, $this->directory);
    }

    public function development(Environment $environment = Environment::Production, bool $debug = false): HttpKernel
    {
        $manifest = $this->manifest();
        $app = new Application(new Runtime($environment, $debug, $this->directory), new Configuration($this->configuration), $manifest);
        $app->register();
        $app->boot();

        return new HttpKernelFactory(new ResponseEmitter($this->sapi))->development($app, $manifest);
    }

    public function cleanUp(): void
    {
        array_map(\unlink(...), glob($this->directory . '/*') ?: []);
        @rmdir($this->directory);
    }
}
