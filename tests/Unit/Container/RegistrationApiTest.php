<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Throwable;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Foundation\Exception\ConfigurationException;
use Trunk\Tests\Fixtures\Di\AListener;
use Trunk\Tests\Fixtures\Di\BListener;
use Trunk\Tests\Fixtures\Di\ConfiguredThing;
use Trunk\Tests\Fixtures\Di\Dispatcher;
use Trunk\Tests\Fixtures\Di\OrderController;
use Trunk\Tests\Fixtures\Di\PaymentGateway;
use Trunk\Tests\Fixtures\Di\PaymentService;
use Trunk\Tests\Fixtures\Di\PaypalGateway;
use Trunk\Tests\Fixtures\Di\StripeGateway;
use Trunk\Tests\Fixtures\Di\Widget;
use Trunk\Tests\Fixtures\Di\WidgetFactory;
use Trunk\Tests\Support\ContainerModes;

final class RegistrationApiTest extends TestCase
{
    public function test_an_interface_is_bound_to_an_implementation_and_the_whole_graph_is_resolved(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static function (ContainerBuilder $b): void {
                $b->bind(PaymentGateway::class, StripeGateway::class);
            },
            roots: [OrderController::class],
        );

        foreach ($containers as $mode => $container) {
            // Act
            // Controllers are roots and therefore scoped: resolve them from a scope, as the kernel does.
            $controller = $container->beginScope()->get(OrderController::class);

            // Assert
            self::assertInstanceOf(OrderController::class, $controller, $mode);
            self::assertSame('stripe', $controller->payments->gateway->name(), $mode);
            self::assertSame('USD', $controller->payments->currency, $mode);
        }
    }

    public function test_a_consumer_can_be_given_a_specific_implementation_by_parameter_name(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->bind(PaymentGateway::class, StripeGateway::class);
            $b->autowire(PaypalGateway::class);
            $b->autowire(PaymentService::class, with: ['gateway' => new Reference(PaypalGateway::class)]);
        });

        foreach ($containers as $mode => $container) {
            // Act
            $service = $container->get(PaymentService::class);

            // Assert
            self::assertInstanceOf(PaymentService::class, $service, $mode);
            self::assertSame('paypal', $service->gateway->name(), $mode);
        }
    }

    public function test_tagged_services_are_injected_in_registration_order(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->autowire(AListener::class);
            $b->autowire(BListener::class);
            $b->tag('listeners', AListener::class, BListener::class);
            $b->service(Dispatcher::class, Dispatcher::class, [new TaggedReference('listeners')]);
            $b->service('empty', Dispatcher::class, [new TaggedReference('nothing')]);
        });

        foreach ($containers as $mode => $container) {
            // Act
            $dispatcher = $container->get(Dispatcher::class);
            $empty = $container->get('empty');

            // Assert
            self::assertInstanceOf(Dispatcher::class, $dispatcher, $mode);
            self::assertInstanceOf(Dispatcher::class, $empty, $mode);
            self::assertSame(['a', 'b'], array_map(static fn($l): string => $l->id(), $dispatcher->listeners), $mode);
            self::assertSame([], $empty->listeners, $mode);
        }
    }

    public function test_configuration_values_are_injected_with_their_declared_type(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(
            static function (ContainerBuilder $b): void {
                $b->autowire(ConfiguredThing::class, with: ['name' => new ConfigValue('app.name', 'string'), 'workers' => new ConfigValue('app.workers', 'int')]);
                $b->service('missing', ConfiguredThing::class, [new ConfigValue('app.name'), new ConfigValue('app.nope')]);
            },
            ['app' => ['name' => 'trunk', 'workers' => 4]],
        );

        foreach ($containers as $mode => $container) {
            // Act
            $thing = $container->get(ConfiguredThing::class);

            // Assert
            self::assertInstanceOf(ConfiguredThing::class, $thing, $mode);
            self::assertSame(['trunk', 4], [$thing->name, $thing->workers], $mode);

            try {
                $container->get('missing');
                self::fail('Expected an exception in ' . $mode);
            } catch (Throwable $e) {
                self::assertInstanceOf(ConfigurationException::class, $e, $mode);
            }
        }
    }

    public function test_a_factory_method_on_a_container_service_creates_the_value(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->autowire(WidgetFactory::class);
            $b->factory(Widget::class, [WidgetFactory::class, 'make'], [new Value('hello')]);
        });

        foreach ($containers as $mode => $container) {
            // Act
            $widget = $container->get(Widget::class);

            // Assert
            self::assertInstanceOf(Widget::class, $widget, $mode);
            self::assertSame('HELLO', $widget->label, $mode);
            self::assertSame($widget, $container->get(Widget::class), $mode);
        }
    }

    public function test_factories_must_be_public_instance_methods_of_real_classes(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $rejected = 0;
        $attempts = [
            [WidgetFactory::class, 'staticMake'],
            [WidgetFactory::class, 'hidden'],
            [WidgetFactory::class, 'missing'],
            ['Does\\Not\\Exist', 'make'],
        ];

        // Act
        foreach ($attempts as $i => $factory) {
            try {
                $builder->factory('w' . $i, $factory);
            } catch (ContainerException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
    }

    public function test_overriding_an_unknown_constructor_parameter_is_an_error(): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act & Assert
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('no constructor parameter named $nope');
        $builder->autowire(PaymentService::class, with: ['nope' => new Value(1)]);
    }

    public function test_invalid_tag_and_config_names_are_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $rejected = 0;

        // Act
        foreach ([static fn() => $builder->tag("t'); x", 'a'), static fn() => new ConfigValue("a'b"), static fn() => new ConfigValue('a', 'float'), static fn() => new TaggedReference('bad tag')] as $attempt) {
            try {
                $attempt();
            } catch (ContainerException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(4, $rejected);
    }
}
