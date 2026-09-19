<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Tests\Fixtures\Services\Logger;
use Trunk\Tests\Support\CompiledContainerLoader;
use Trunk\Tests\Support\ForbiddenConstructScanner;

final class CompilerSecurityTest extends TestCase
{
    private const string PAYLOAD = "x'); system('id'); //";

    public function test_generated_source_contains_no_dangerous_constructs_even_for_hostile_ids_and_values(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->service(self::PAYLOAD, Logger::class);
        $builder->alias('alias', self::PAYLOAD);
        $builder->service('hostile.value', ArrayObject::class, [new Value([self::PAYLOAD])]);

        // Act
        $source = new ContainerCompiler()->compile($builder, [], []);
        $violations = new ForbiddenConstructScanner()->scan($source, 'generated');

        // Assert
        self::assertSame([], $violations);
    }

    public function test_hostile_ids_are_data_not_code_when_the_container_runs(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->service(self::PAYLOAD, Logger::class);
        $loader = new CompiledContainerLoader();
        $name = $loader->uniqueName();
        $class = $loader->load(new ContainerCompiler()->compile($builder, [], [], $name), $name);

        // Act
        $service = new $class()->get(self::PAYLOAD);

        // Assert
        self::assertInstanceOf(Logger::class, $service);
    }

    public function test_class_names_containing_code_are_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act & Assert
        $this->expectException(ContainerException::class);
        $builder->service('svc', "Foo'); system('id'); //");
    }

    public function test_the_compiled_class_name_must_be_a_plain_identifier(): void
    {
        // Arrange
        $compiler = new ContainerCompiler();

        // Act & Assert
        $this->expectException(CompilationException::class);
        $compiler->compile(new ContainerBuilder(), [], [], 'Evil {} system("id"); class X');
    }
}
