<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Application;

use PHPUnit\Framework\TestCase;
use Trunk\Application\Application;
use Trunk\Application\Exception\LifecycleException;
use Trunk\Application\Phase;
use Trunk\Compiler\ArtifactWriter;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Tests\Fixtures\Modules\AlphaModule;
use Trunk\Tests\Fixtures\Modules\CompilableModule;
use Trunk\Tests\Fixtures\Services\Mailer;

final class CompiledApplicationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/trunk-build-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        array_map(\unlink(...), glob($this->directory . '/*') ?: []);
        @rmdir($this->directory);
    }

    public function test_application_boots_from_a_compiled_container(): void
    {
        // Arrange
        $manifest = new ModuleManifest([CompilableModule::class]);
        $class = $this->build($manifest);
        $app = $this->application($manifest, $class);

        // Act
        $app->register();
        $app->boot();

        // Assert
        self::assertSame(Phase::Booted, $app->phase());
        self::assertInstanceOf($class, $app->container());
        self::assertInstanceOf(Mailer::class, $app->container()->get('mailer'));
        self::assertInstanceOf(Runtime::class, $app->container()->get(Runtime::class));
        self::assertSame(['Trunk\\Tests\\Fixtures\\Modules\\CompilableModule'], array_values((array) (require $this->directory . '/modules.php')));
    }

    public function test_a_container_compiled_from_another_module_set_is_rejected(): void
    {
        // Arrange
        $class = $this->build(new ModuleManifest([CompilableModule::class]));
        $app = $this->application(new ModuleManifest([]), $class);

        // Act & Assert
        $this->expectException(LifecycleException::class);
        $app->register();
    }

    public function test_building_modules_with_closure_bindings_fails(): void
    {
        // Arrange
        $manifest = new ModuleManifest([AlphaModule::class]);

        // Act & Assert
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('"log" is bound to a closure');
        new ArtifactWriter()->write($manifest, $this->directory);
    }

    /**
     * @return class-string<\Trunk\Container\CompiledContainer>
     */
    private function build(ModuleManifest $manifest): string
    {
        $name = 'App' . bin2hex(random_bytes(4));
        new ArtifactWriter()->write($manifest, $this->directory, $name);
        require $this->directory . '/container.php';
        $class = 'Trunk\\Compiled\\' . $name;

        return is_subclass_of($class, \Trunk\Container\CompiledContainer::class) ? $class : self::fail('Not generated.');
    }

    private function application(ModuleManifest $manifest, ?string $container = null): Application
    {
        return new Application(
            new Runtime(Environment::Production, false, __DIR__),
            new Configuration(['app' => ['name' => 'Trunk']]),
            $manifest,
            $container !== null && is_subclass_of($container, \Trunk\Container\CompiledContainer::class) ? $container : null,
        );
    }
}
