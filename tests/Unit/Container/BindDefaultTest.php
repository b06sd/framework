<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Trunk\Container\ContainerBuilder;
use Trunk\Tests\Fixtures\Di\PaymentGateway;
use Trunk\Tests\Fixtures\Di\PaypalGateway;
use Trunk\Tests\Fixtures\Di\StripeGateway;
use Trunk\Tests\Support\ContainerModes;

final class BindDefaultTest extends TestCase
{
    public function test_a_default_is_used_when_nothing_else_binds_the_id(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->bindDefault(PaymentGateway::class, StripeGateway::class);
        }, roots: [PaymentGateway::class]);

        foreach ($containers as $mode => $container) {
            // Act
            $gateway = $container->get(PaymentGateway::class);

            // Assert
            self::assertSame('stripe', $gateway instanceof PaymentGateway ? $gateway->name() : '', $mode);
        }
    }

    public function test_a_module_binding_replaces_the_default_whatever_the_registration_order(): void
    {
        // Arrange
        $before = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->bind(PaymentGateway::class, PaypalGateway::class);
            $b->bindDefault(PaymentGateway::class, StripeGateway::class);
        }, roots: [PaymentGateway::class]);
        $after = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->bindDefault(PaymentGateway::class, StripeGateway::class);
            $b->bind(PaymentGateway::class, PaypalGateway::class);
        }, roots: [PaymentGateway::class]);

        foreach ([...$before, ...$after] as $container) {
            // Act
            $gateway = $container->get(PaymentGateway::class);

            // Assert
            self::assertSame('paypal', $gateway instanceof PaymentGateway ? $gateway->name() : '');
        }
    }

    public function test_a_pending_default_does_not_count_as_bound(): void
    {
        // Arrange
        $builder = new ContainerBuilder();

        // Act
        $builder->bindDefault(PaymentGateway::class, StripeGateway::class);

        // Assert
        self::assertFalse($builder->has(PaymentGateway::class));
        $builder->bind(PaymentGateway::class, PaypalGateway::class);
        self::assertTrue($builder->has(PaymentGateway::class));
    }
}
