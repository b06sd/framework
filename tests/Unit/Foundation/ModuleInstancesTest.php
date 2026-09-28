<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Foundation;

use PHPUnit\Framework\TestCase;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Foundation\Manifest\ModuleInstances;
use Trunk\Tests\Fixtures\Modules\Graph\BaseModule;
use Trunk\Tests\Fixtures\Modules\ThrowingConstructorModule;

final class ModuleInstancesTest extends TestCase
{
    public function test_from_constructs_every_class_in_order(): void
    {
        // Act
        $modules = ModuleInstances::from([BaseModule::class, BaseModule::class]);

        // Assert
        self::assertCount(2, $modules);
        self::assertInstanceOf(BaseModule::class, $modules[0]);
        self::assertInstanceOf(BaseModule::class, $modules[1]);
        self::assertNotSame($modules[0], $modules[1], 'each entry is its own instance');
    }

    public function test_one_names_the_module_when_its_constructor_throws(): void
    {
        // Act
        try {
            ModuleInstances::one(ThrowingConstructorModule::class);
            self::fail('expected a ConfigurationException');
        } catch (ConfigurationException $e) {
            // Assert
            self::assertStringContainsString(ThrowingConstructorModule::class, $e->getMessage());
            self::assertStringContainsString('the module is unhappy', $e->getMessage());
            self::assertNotNull($e->getPrevious());
            self::assertSame('the module is unhappy', $e->getPrevious()->getMessage());
        }
    }

    public function test_from_stops_at_the_first_module_that_fails_to_construct(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(ThrowingConstructorModule::class, '/') . '/');

        ModuleInstances::from([BaseModule::class, ThrowingConstructorModule::class]);
    }
}
