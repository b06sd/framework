<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Trunk\Container\ContainerBuilder;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Scopable;
use Trunk\Http\Message\ServerRequest;
use Trunk\Tests\Fixtures\Di\RequestContext;
use Trunk\Tests\Fixtures\Di\Sibling;
use Trunk\Tests\Fixtures\Di\Token;
use Trunk\Tests\Support\ContainerModes;

final class LifetimeTest extends TestCase
{
    public function test_singletons_are_shared_and_transients_are_not_in_both_container_kinds(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->singleton(Token::class);
            $b->alias('token.alias', Token::class);
        });

        foreach ($containers as $mode => $container) {
            // Act
            $sameSingleton = $container->get(Token::class) === $container->get(Token::class);
            $viaAlias = $container->get('token.alias') === $container->get(Token::class);

            // Assert
            self::assertTrue($sameSingleton, $mode);
            self::assertTrue($viaAlias, $mode);
        }
    }

    public function test_scoped_services_live_once_per_scope_and_singletons_stay_shared(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->scoped(RequestContext::class);
            $b->scoped(Sibling::class);
            $b->singleton(Token::class);
            $b->transient('fresh', Token::class);
        });

        foreach ($containers as $mode => $root) {
            $requestA = new ServerRequest('GET', '/a');
            $requestB = new ServerRequest('GET', '/b');

            // Act
            $scopeA = $root->beginScope([ServerRequestInterface::class => $requestA]);
            $scopeB = $root->beginScope([ServerRequestInterface::class => $requestB]);
            $contextA = $scopeA->get(RequestContext::class);
            $contextB = $scopeB->get(RequestContext::class);
            $sibling = $scopeA->get(Sibling::class);

            // Assert
            self::assertInstanceOf(RequestContext::class, $contextA, $mode);
            self::assertInstanceOf(RequestContext::class, $contextB, $mode);
            self::assertInstanceOf(Sibling::class, $sibling, $mode);
            self::assertSame($contextA, $scopeA->get(RequestContext::class), $mode);
            self::assertNotSame($contextA, $contextB, $mode);
            self::assertSame($requestA, $contextA->request, $mode);
            self::assertSame($contextA, $sibling->context, $mode);
            self::assertSame($scopeA->get(Token::class), $scopeB->get(Token::class), $mode);
            self::assertSame($root->get(Token::class), $scopeA->get(Token::class), $mode);
        }
    }

    public function test_a_scoped_service_cannot_be_resolved_outside_a_scope(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->scoped(Token::class);
        });

        foreach ($containers as $mode => $container) {
            // Act & Assert
            try {
                $container->get(Token::class);
                self::fail('Expected an exception in ' . $mode);
            } catch (ContainerException $e) {
                self::assertStringContainsString('outside a scope', $e->getMessage(), $mode);
            }
        }
    }

    public function test_transient_services_are_created_every_time(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->transient(Token::class);
        });

        foreach ($containers as $mode => $container) {
            // Act
            $first = $container->get(Token::class);
            $second = $container->get(Token::class);

            // Assert
            self::assertInstanceOf(Token::class, $first, $mode);
            self::assertNotSame($first, $second, $mode);
        }
    }

    public function test_a_transient_resolved_inside_a_scope_is_still_fresh(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->transient(Token::class);
        });

        foreach ($containers as $mode => $root) {
            $scope = $root->beginScope();

            // Act & Assert
            self::assertNotSame($scope->get(Token::class), $scope->get(Token::class), $mode);
        }
    }

    public function test_the_container_offers_itself_only_as_the_narrow_scope_factory(): void
    {
        // Arrange
        $containers = new ContainerModes()->both(static function (ContainerBuilder $b): void {
            $b->singleton(Token::class);
        });

        foreach ($containers as $mode => $root) {
            // Act
            $scopes = $root->get(Scopable::class);
            $fromScope = $root->beginScope()->get(Scopable::class);

            // Assert
            self::assertInstanceOf(Scopable::class, $scopes, $mode);
            self::assertSame($root, $scopes, $mode);
            self::assertSame($root, $fromScope, $mode);
            self::assertTrue($root->has(Scopable::class), $mode);
        }
    }
}
