<?php

declare(strict_types=1);

namespace Trunk\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Tests\Fixtures\Di\Dispatcher;
use Trunk\Tests\Fixtures\Di\Token;
use Trunk\Tests\Support\CompiledContainerLoader;
use Trunk\Tests\Support\ForbiddenConstructScanner;

final class ContainerSecurityTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileRoots(): iterable
    {
        yield 'path traversal' => ['../../etc/passwd'];
        yield 'namespace traversal' => ['Trunk\\..\\..\\Secret'];
        yield 'code injection' => ["Foo'); system('id'); //"];
        yield 'nul byte' => ["Trunk\\Tests\\Fixtures\\Di\\Token\0"];
        yield 'url' => ['https://evil.test/Payload'];
        yield 'empty' => [''];
    }

    #[DataProvider('hostileRoots')]
    public function test_automatic_resolution_only_ever_wires_valid_class_names(string $root): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act & Assert
        $this->expectException(CompilationException::class);
        new ContainerCompiler()->plan($builder, [], [], 'AppContainer', [$root]);
    }

    public function test_classes_that_nothing_references_are_never_registered(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->singleton(Token::class);

        // Act
        $plan = new ContainerCompiler()->plan($builder, [], [], 'AppContainer', []);

        // Assert
        self::assertSame([], $plan->autoRegistered);
        self::assertSame([Token::class], $plan->boundIds);
    }

    public function test_hostile_ids_tags_and_values_stay_data_in_generated_code(): void
    {
        // Arrange
        $hostile = "x'); system('id'); //";
        $builder = new ContainerBuilder();
        $builder->service($hostile, Token::class);
        $builder->tag('listeners', $hostile);
        $builder->service(Dispatcher::class, Dispatcher::class, [new TaggedReference('listeners')]);
        $loader = new CompiledContainerLoader();
        $name = $loader->uniqueName();
        $source = new ContainerCompiler()->compile($builder, [], [], $name);
        $class = $loader->load($source, $name);

        // Act
        $violations = new ForbiddenConstructScanner()->scan($source, 'generated');
        $dispatcher = new $class()->get(Dispatcher::class);

        // Assert
        self::assertSame([], $violations);
        self::assertInstanceOf(Dispatcher::class, $dispatcher);
        self::assertCount(1, $dispatcher->listeners);
    }
}
