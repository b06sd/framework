<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Tests\Fixtures\Services\Logger;
use Trunk\Tests\Fixtures\Services\Mailer;

final class DeclarativeBindingsTest extends TestCase
{
    public function test_declarative_bindings_resolve_in_development_mode(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->autowire(Logger::class);
        $builder->service(Mailer::class, Mailer::class, [new Reference(Logger::class), new Value('a@b.c')]);
        $builder->alias('mailer', Mailer::class);
        $container = $builder->build();

        // Act
        $mailer = $container->get('mailer');

        // Assert
        self::assertInstanceOf(Mailer::class, $mailer);
        self::assertSame($container->get(Logger::class), $mailer->logger);
        self::assertSame('a@b.c', $mailer->from);
        self::assertSame($mailer, $container->get(Mailer::class));
    }

    public function test_declarative_and_closure_bindings_share_one_id_space(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance('x', 1);

        // Act & Assert
        $this->expectException(ContainerException::class);
        $builder->alias('x', 'y');
    }

    public function test_integer_like_ids_are_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act & Assert
        $this->expectException(ContainerException::class);
        $builder->instance('123', 1);
    }
}
