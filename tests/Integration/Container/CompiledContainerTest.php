<?php

declare(strict_types=1);

namespace Trunk\Tests\Integration\Container;

use PHPUnit\Framework\TestCase;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\NotFoundException;
use Trunk\Tests\Fixtures\Services\Greeter;
use Trunk\Tests\Fixtures\Services\Logger;
use Trunk\Tests\Fixtures\Services\Mailer;
use Trunk\Tests\Support\CompiledContainerLoader;

final class CompiledContainerTest extends TestCase
{
    public function test_compiled_container_resolves_the_same_graph_as_the_development_container(): void
    {
        // Arrange
        $loader = new CompiledContainerLoader();
        $name = $loader->uniqueName();
        $class = $loader->load(new ContainerCompiler()->compile($this->builder(), [], [], $name), $name);
        $compiled = new $class();
        $development = $this->builder()->build();

        // Act
        $greeter = $compiled->get(Greeter::class);
        $expected = $development->get(Greeter::class);

        // Assert
        self::assertInstanceOf(Greeter::class, $greeter);
        self::assertInstanceOf(Greeter::class, $expected);
        self::assertSame($expected->retries, $greeter->retries);
        self::assertSame($expected->mailer->from, $greeter->mailer->from);
        self::assertSame($compiled->get('mailer'), $greeter->mailer);
        self::assertSame($compiled->get(Logger::class), $greeter->mailer->logger);
    }

    public function test_external_instances_are_available_and_unknown_ids_are_not_found(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->service('mailer', Mailer::class, [new Reference(Logger::class), new Value('x')]);
        $builder->autowire(Logger::class);
        $loader = new CompiledContainerLoader();
        $name = $loader->uniqueName();
        $class = $loader->load(new ContainerCompiler()->compile($builder, [], ['ext'], $name), $name);
        $container = new $class(['ext' => 'supplied']);

        // Act
        $has = [$container->has('ext'), $container->has('mailer'), $container->has('nope')];

        // Assert
        self::assertSame('supplied', $container->get('ext'));
        self::assertSame([true, true, false], $has);
        $this->expectException(NotFoundException::class);
        $container->get('nope');
    }

    private function builder(): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $builder->autowire(Logger::class);
        $builder->service(Mailer::class, Mailer::class, [new Reference(Logger::class), new Value('a@b.c')]);
        $builder->autowire(Greeter::class);
        $builder->alias('mailer', Mailer::class);

        return $builder;
    }
}
