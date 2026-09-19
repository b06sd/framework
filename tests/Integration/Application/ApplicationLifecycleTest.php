<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Application;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Trunk\Application\Application;
use Trunk\Application\Exception\LifecycleException;
use Trunk\Application\Phase;
use Trunk\Foundation\Configuration;
use Trunk\Foundation\Environment;
use Trunk\Foundation\Manifest\ModuleManifest;
use Trunk\Foundation\Runtime;
use Trunk\Tests\Fixtures\Modules\AlphaModule;
use Trunk\Tests\Fixtures\Modules\BetaModule;

final class ApplicationLifecycleTest extends TestCase
{
    public function test_modules_register_then_boot_in_manifest_order(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $app->register();
        $app->boot();
        $log = $app->container()->get('log');

        // Assert
        self::assertSame(Phase::Booted, $app->phase());
        self::assertInstanceOf(ArrayObject::class, $log);
        self::assertSame(['alpha', 'beta'], $log->getArrayCopy());
        self::assertTrue($app->container()->has('beta'));
    }

    public function test_runtime_and_configuration_are_available_from_the_container(): void
    {
        // Arrange
        $app = $this->application();

        // Act
        $app->register();

        // Assert
        $config = $app->container()->get(Configuration::class);
        self::assertInstanceOf(Runtime::class, $app->container()->get(Runtime::class));
        self::assertInstanceOf(Configuration::class, $config);
        self::assertSame('Trunk', $config->string('app.name'));
    }

    public function test_container_is_unavailable_before_registration(): void
    {
        // Arrange
        $app = $this->application();

        // Act & Assert
        $this->expectException(LifecycleException::class);
        $app->container();
    }

    public function test_boot_before_register_is_rejected(): void
    {
        // Arrange
        $app = $this->application();

        // Act & Assert
        $this->expectException(LifecycleException::class);
        $app->boot();
    }

    public function test_phases_cannot_run_twice(): void
    {
        // Arrange
        $app = $this->application();
        $app->register();
        $app->boot();

        // Act & Assert
        $this->expectException(LifecycleException::class);
        $app->boot();
    }

    public function test_register_cannot_run_twice(): void
    {
        // Arrange
        $app = $this->application();
        $app->register();

        // Act & Assert
        $this->expectException(LifecycleException::class);
        $app->register();
    }
    private function application(): Application
    {
        return new Application(
            new Runtime(Environment::Testing, true, __DIR__),
            new Configuration(['app' => ['name' => 'Trunk']]),
            new ModuleManifest([AlphaModule::class, BetaModule::class]),
        );
    }
}
