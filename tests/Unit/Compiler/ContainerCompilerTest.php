<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\Reference;
use Trunk\Tests\Fixtures\Services\CycleA;
use Trunk\Tests\Fixtures\Services\CycleB;
use Trunk\Tests\Fixtures\Services\Logger;
use Trunk\Tests\Fixtures\Services\Mailer;

final class ContainerCompilerTest extends TestCase
{
    public function test_closure_bindings_are_reported_by_id(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance('closure.one', 1);

        // Act
        $errors = $this->errorsFor($builder);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('closure.one', $errors[0]);
    }

    public function test_unresolved_references_are_reported(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->service('mailer', Mailer::class, [new Reference('missing')]);
        $builder->alias('alias', 'also.missing');

        // Act
        $errors = $this->errorsFor($builder);

        // Assert
        self::assertCount(2, $errors);
        self::assertStringContainsString('"missing"', $errors[0]);
        self::assertStringContainsString('"also.missing"', $errors[1]);
    }

    public function test_circular_dependencies_are_reported_with_their_path(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->autowire(CycleA::class);
        $builder->autowire(CycleB::class);

        // Act
        $errors = $this->errorsFor($builder);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString(CycleA::class . ' -> ' . CycleB::class . ' -> ' . CycleA::class, $errors[0]);
    }

    public function test_external_ids_satisfy_references_but_cannot_be_rebound(): void
    {
        // Arrange
        $ok = new ContainerBuilder();
        $ok->service('mailer', Mailer::class, [new Reference('ext'), new Reference('ext')]);
        $conflict = new ContainerBuilder();
        $conflict->autowire(Logger::class);

        // Act
        $source = new ContainerCompiler()->compile($ok, [], ['ext']);
        $errors = $this->errorsFor($conflict, Logger::class);

        // Assert
        self::assertStringContainsString("'mailer' => new \\" . Mailer::class, $source);
        self::assertStringContainsString('provided at runtime', $errors[0]);
    }

    public function test_empty_graph_compiles_to_valid_php(): void
    {
        // Arrange
        $compiler = new ContainerCompiler();

        // Act
        $source = $compiler->compile(new ContainerBuilder(), [], []);

        // Assert
        self::assertStringContainsString('return null;', $source);
        self::assertStringContainsString('NotFoundException(', $source);
    }
    /**
     * @return list<string>
     */
    private function errorsFor(ContainerBuilder $builder, string ...$external): array
    {
        try {
            new ContainerCompiler()->compile($builder, [], array_values($external));
        } catch (CompilationException $e) {
            return $e->errors;
        }

        self::fail('Expected a CompilationException.');
    }
}
