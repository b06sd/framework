<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use stdClass;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Exception\ContainerException;

final class ContainerTest extends TestCase
{
    public function test_a_service_is_created_lazily_and_only_once(): void
    {
        // Arrange
        $calls = 0;
        $builder = new ContainerBuilder();
        $builder->closure('service', static function () use (&$calls): stdClass {
            ++$calls;

            return new stdClass();
        });
        $container = $builder->build();
        $callsBeforeGet = $calls;

        // Act
        $first = $container->get('service');
        $second = $container->get('service');

        // Assert
        self::assertSame(0, $callsBeforeGet);
        self::assertSame(1, $calls);
        self::assertSame($first, $second);
    }

    public function test_factories_can_resolve_other_services(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance('name', 'trunk');
        $builder->closure('greeting', static fn(ContainerInterface $c): array => ['hello', $c->get('name')]);

        // Act
        $result = $builder->build()->get('greeting');

        // Assert
        self::assertSame(['hello', 'trunk'], $result);
    }

    public function test_unknown_id_throws_a_psr_not_found_exception(): void
    {
        // Arrange
        $container = new ContainerBuilder()->build();

        // Act & Assert
        $this->expectException(NotFoundExceptionInterface::class);
        $container->get('missing');
    }

    public function test_circular_dependencies_are_detected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->closure('a', static fn(ContainerInterface $c): mixed => $c->get('b'));
        $builder->closure('b', static fn(ContainerInterface $c): mixed => $c->get('a'));
        $container = $builder->build();

        // Act & Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular');
        $container->get('a');
    }

    public function test_binding_the_same_id_twice_is_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance('x', 1);

        // Act & Assert
        $this->expectException(ContainerException::class);
        $builder->instance('x', 2);
    }

    public function test_has_reports_bound_ids(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance('x', null);
        $container = $builder->build();

        // Act & Assert
        self::assertTrue($container->has('x'));
        self::assertFalse($container->has('y'));
        self::assertNull($container->get('x'));
    }
}
