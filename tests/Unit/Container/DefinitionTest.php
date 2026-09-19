<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use stdClass;
use Trunk\Container\Autowirer;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\ServiceDefinition;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Tests\Fixtures\Services\Contract;
use Trunk\Tests\Fixtures\Services\Greeter;
use Trunk\Tests\Fixtures\Services\Mailer;
use Trunk\Tests\Fixtures\Services\NeedsScalar;

final class DefinitionTest extends TestCase
{
    public function test_value_accepts_scalars_null_and_nested_arrays(): void
    {
        // Arrange

        // Act
        $value = new Value(['a' => [1, 2.5, true, null, 'x']]);

        // Assert
        self::assertSame(['a' => [1, 2.5, true, null, 'x']], $value->value);
    }

    public function test_value_rejects_objects(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(ContainerException::class);
        new Value(['nested' => new stdClass()]);
    }

    public function test_service_definition_rejects_unknown_classes(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(ContainerException::class);
        new ServiceDefinition('x', 'Does\\Not\\Exist');
    }

    public function test_service_definition_rejects_interfaces(): void
    {
        // Arrange

        // Act & Assert
        $this->expectException(ContainerException::class);
        new ServiceDefinition('x', Contract::class);
    }

    public function test_autowirer_maps_class_types_to_references_and_defaults_to_values(): void
    {
        // Arrange
        $autowirer = new Autowirer();

        // Act
        $definition = $autowirer->definitionFor(Greeter::class);

        // Assert
        self::assertSame(Greeter::class, $definition->id);
        self::assertEquals(Mailer::class, $definition->arguments[0] instanceof Reference ? $definition->arguments[0]->id : null);
        self::assertEquals(3, $definition->arguments[1] instanceof Value ? $definition->arguments[1]->value : null);
    }

    public function test_autowirer_rejects_scalar_parameters_without_defaults(): void
    {
        // Arrange
        $autowirer = new Autowirer();

        // Act & Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('$name');
        $autowirer->definitionFor(NeedsScalar::class);
    }
}
