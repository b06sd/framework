<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Definition;

use PHPUnit\Framework\TestCase;
use Trunk\Router\Definition\RouteCollector;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Tests\Fixtures\Controllers\HomeController;
use Trunk\Tests\Fixtures\Controllers\UserController;

final class RouteCollectorTest extends TestCase
{
    public function test_verb_helpers_register_routes_with_names(): void
    {
        // Arrange
        $routes = new RouteCollector();

        // Act
        $routes->get('/a', [UserController::class, 'index'], 'a');
        $routes->post('/b', [UserController::class, 'show']);
        $routes->any('/c', HomeController::class);
        $all = $routes->routes();

        // Assert
        self::assertSame(['GET'], $all[0]->methods);
        self::assertSame('a', $all[0]->name);
        self::assertSame(['POST'], $all[1]->methods);
        self::assertContains('DELETE', $all[2]->methods);
        self::assertSame([HomeController::class, '__invoke'], $all[2]->handler);
    }

    public function test_groups_prefix_patterns_and_can_be_nested(): void
    {
        // Arrange
        $routes = new RouteCollector();

        // Act
        $routes->group('/api', static function (RouteCollector $api): void {
            $api->get('/', [UserController::class, 'index']);
            $api->group('/v1', static function (RouteCollector $v1): void {
                $v1->get('/users/{id}', [UserController::class, 'show']);
            });
        });
        $patterns = array_map(static fn($r): string => $r->pattern, $routes->routes());

        // Assert
        self::assertSame(['/api', '/api/v1/users/{id}'], $patterns);
    }

    public function test_closure_handlers_are_rejected_because_they_cannot_be_compiled(): void
    {
        // Arrange
        $routes = new RouteCollector();

        // Act & Assert
        $this->expectException(InvalidRouteException::class);
        $this->expectExceptionMessage('closures cannot be compiled');
        $routes->get('/x', static function (): void {});
    }

    public function test_unknown_classes_missing_and_non_public_methods_are_rejected(): void
    {
        // Arrange
        $routes = new RouteCollector();
        $rejected = 0;
        $attempts = [
            ['Does\\Not\\Exist', 'run'],
            [UserController::class, 'missing'],
            [UserController::class, 'hidden'],
            [UserController::class, 'staticAction'],
            "Foo'); system('id'); //",
        ];

        // Act
        foreach ($attempts as $handler) {
            try {
                $routes->get('/x', $handler);
            } catch (InvalidRouteException) {
                ++$rejected;
            }
        }

        // Assert
        self::assertSame(\count($attempts), $rejected);
        self::assertSame([], $routes->routes());
    }

    public function test_invalid_patterns_fail_at_registration_time(): void
    {
        // Arrange
        $routes = new RouteCollector();

        // Act & Assert
        $this->expectException(InvalidRouteException::class);
        $routes->get('/a/{id:(a|b)+}', [UserController::class, 'show']);
    }

    public function test_group_prefixes_must_be_well_formed(): void
    {
        // Arrange
        $routes = new RouteCollector();

        // Act & Assert
        $this->expectException(InvalidRouteException::class);
        $routes->group('api/', static function (): void {});
    }
}
