<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Compiler;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Compiler\ContainerCompiler;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Lifetime;
use Trunk\Foundation\Configuration;
use Trunk\Tests\Fixtures\Di\OrderController;
use Trunk\Tests\Fixtures\Di\PaymentGateway;
use Trunk\Tests\Fixtures\Di\PaymentService;
use Trunk\Tests\Fixtures\Di\RequestContext;
use Trunk\Tests\Fixtures\Di\SingletonNeedingContext;
use Trunk\Tests\Fixtures\Di\SingletonNeedingRequest;
use Trunk\Tests\Fixtures\Di\StripeGateway;
use Trunk\Tests\Fixtures\Di\Token;
use Trunk\Tests\Fixtures\Services\CycleA;
use Trunk\Tests\Fixtures\Services\NeedsScalar;

final class GraphAnalysisTest extends TestCase
{
    public function test_concrete_classes_reachable_from_the_roots_are_wired_automatically_and_reported(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->bind(PaymentGateway::class, StripeGateway::class);

        // Act
        $plan = new ContainerCompiler()->plan($builder, [], [], 'AppContainer', [OrderController::class]);

        // Assert
        self::assertSame([OrderController::class, PaymentService::class], $plan->autoRegistered);
        self::assertContains(OrderController::class, $plan->boundIds);
    }

    public function test_an_interface_without_a_binding_is_reported_with_the_path_and_a_fix(): void
    {
        // Arrange

        // Act
        $errors = $this->errors(static fn(ContainerBuilder $b) => null, [OrderController::class]);

        // Assert
        self::assertCount(1, $errors);
        self::assertStringContainsString('Cannot resolve "' . PaymentGateway::class . '"', $errors[0]);
        self::assertStringContainsString('(needed by ' . PaymentService::class . '::__construct($gateway))', $errors[0]);
        self::assertStringContainsString('interface with no binding', $errors[0]);
        self::assertStringContainsString('Path: ' . OrderController::class . ' -> ' . PaymentService::class . ' -> ' . PaymentGateway::class, $errors[0]);
        self::assertStringContainsString('$builder->bind(' . PaymentGateway::class . '::class, YourImplementation::class);', $errors[0]);
    }

    public function test_unknown_ids_and_scalar_parameters_are_explained(): void
    {
        // Arrange

        // Act
        $unknown = $this->errors(static fn(ContainerBuilder $b) => null, ['not.a.service']);
        $scalar = $this->errors(static fn(ContainerBuilder $b) => null, [NeedsScalar::class]);

        // Assert
        self::assertStringContainsString('it is not a class and has no binding', $unknown[0]);
        self::assertStringContainsString('parameter $name has no class type', $scalar[0]);
        self::assertStringContainsString('ConfigValue', $scalar[0]);
    }

    public function test_circular_constructor_dependencies_are_reported_with_the_cycle(): void
    {
        // Arrange

        // Act
        $errors = $this->errors(static fn(ContainerBuilder $b) => null, [CycleA::class]);

        // Assert
        self::assertStringContainsString('Circular dependency: ' . CycleA::class . ' -> ', $errors[0]);
    }

    public function test_a_singleton_cannot_capture_a_scoped_service_directly_transitively_or_through_an_alias(): void
    {
        // Arrange
        $direct = $this->errors(static function (ContainerBuilder $b): void {
            $b->scoped(RequestContext::class);
            $b->singleton(SingletonNeedingContext::class);
        });
        $viaAlias = $this->errors(static function (ContainerBuilder $b): void {
            $b->scoped(RequestContext::class);
            $b->alias('context', RequestContext::class);
            $b->service('holder', SingletonNeedingContext::class, [new \Trunk\Container\Definition\Reference('context')]);
        });
        $viaTransient = $this->errors(static function (ContainerBuilder $b): void {
            $b->scoped(RequestContext::class);
            $b->transient(SingletonNeedingContext::class);
            $b->service('outer', \Trunk\Tests\Fixtures\Di\Sibling::class, [new \Trunk\Container\Definition\Reference(SingletonNeedingContext::class)]);
        });
        $onRequest = $this->errors(static function (ContainerBuilder $b): void {
            $b->singleton(SingletonNeedingRequest::class);
        });

        // Assert
        self::assertStringContainsString('depends on scoped service "' . RequestContext::class . '"', $direct[0]);
        self::assertStringContainsString('holder', $viaAlias[0]);
        self::assertStringContainsString('outer', $viaTransient[0]);
        self::assertStringContainsString('depends on scoped service "' . ServerRequestInterface::class . '"', $onRequest[0]);
    }

    public function test_scoped_and_transient_services_may_depend_on_anything(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->singleton(Token::class);
        $builder->scoped(RequestContext::class);
        $builder->scoped(SingletonNeedingContext::class);
        $builder->transient(\Trunk\Tests\Fixtures\Di\Sibling::class);

        // Act
        $plan = new ContainerCompiler()->plan($builder, [], [], 'AppContainer', [], [ServerRequestInterface::class]);

        // Assert
        self::assertNotSame('', $plan->source);
    }

    public function test_a_config_value_needs_configuration_to_be_provided_at_runtime(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->service('thing', \Trunk\Tests\Fixtures\Di\ConfiguredThing::class, [new ConfigValue('a.b'), new ConfigValue('c', 'int')]);

        // Act & Assert
        $this->expectException(CompilationException::class);
        $this->expectExceptionMessage('Configuration is not provided at runtime');
        new ContainerCompiler()->plan($builder, [], [], 'AppContainer');
    }

    public function test_lifetimes_are_compiled_into_the_generated_class(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->singleton(Token::class);
        $builder->transient('t', Token::class);
        $builder->scoped(RequestContext::class);

        // Act
        $source = new ContainerCompiler()->compile($builder, [], [], 'AppContainer', [], [ServerRequestInterface::class]);

        // Assert
        self::assertStringContainsString('\\' . Lifetime::class . '::Singleton', $source);
        self::assertStringContainsString('\\' . Lifetime::class . '::Scoped', $source);
        self::assertStringContainsString('\\' . Lifetime::class . '::Transient', $source);
    }

    public function test_the_development_container_explains_a_captive_dependency_at_runtime(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->scoped(RequestContext::class);
        $builder->singleton(SingletonNeedingContext::class);
        $container = $builder->build();

        // Act & Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('must not depend on request-scoped services');
        $container->get(SingletonNeedingContext::class);
    }

    public function test_the_development_container_autowires_unbound_concrete_classes_but_not_interfaces(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->bind(PaymentGateway::class, StripeGateway::class);
        $container = $builder->build();

        // Act
        $controller = $container->get(OrderController::class);

        // Assert
        self::assertInstanceOf(OrderController::class, $controller);
        $this->expectExceptionMessage('interface with no binding');
        new ContainerBuilder()->build()->get(PaymentGateway::class);
    }
    /**
     * @param callable(ContainerBuilder): void $define
     * @param list<string>                     $roots
     *
     * @return list<string>
     */
    private function errors(callable $define, array $roots = []): array
    {
        $builder = new ContainerBuilder();
        $define($builder);

        try {
            new ContainerCompiler()->plan($builder, [], [Configuration::class], 'AppContainer', $roots, [ServerRequestInterface::class]);
        } catch (CompilationException $e) {
            return $e->errors;
        }

        self::fail('Expected a CompilationException.');
    }
}
