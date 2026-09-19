<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Router\Definition;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trunk\Router\Definition\ArgumentPlanner;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Pattern\PatternParser;
use Trunk\Tests\Fixtures\Controllers\PlanController;

final class ArgumentPlannerTest extends TestCase
{
    public function test_request_route_params_and_defaults_are_planned(): void
    {
        // Arrange
        $pattern = '/items/{id:int}';

        // Act
        $plan = new ArgumentPlanner()->plan(PlanController::class, 'ok', new PatternParser()->parse($pattern), $pattern);

        // Assert
        self::assertSame(['request', 'param', 'default'], array_column($plan, 'kind'));
        self::assertSame('int', $plan[1]['type']);
        self::assertSame('x', $plan[2]['default']);
    }

    public function test_an_optional_route_parameter_can_bind_to_a_nullable_parameter(): void
    {
        // Arrange
        $pattern = '/blog/{page?}';

        // Act
        $plan = new ArgumentPlanner()->plan(PlanController::class, 'nullableOptional', new PatternParser()->parse($pattern), $pattern);

        // Assert
        self::assertTrue($plan[0]['nullable']);
        self::assertTrue($plan[0]['hasDefault']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unbindable(): iterable
    {
        yield 'optional param needs a default' => ['requiredForOptional', '/blog/{page?}'];
        yield 'variadic' => ['variadic', '/a/{parts}'];
        yield 'union type' => ['union', '/a/{id}'];
        yield 'class-typed route param' => ['classParam', '/a/{id}'];
        yield 'untyped and unmatched' => ['unbindable', '/a'];
        yield 'non-scalar default' => ['enumDefault', '/a'];
    }

    #[DataProvider('unbindable')]
    public function test_parameters_that_cannot_be_bound_are_rejected_at_build_time(string $method, string $pattern): void
    {
        // Arrange
        $planner = new ArgumentPlanner();

        // Act & Assert
        $this->expectException(InvalidRouteException::class);
        $planner->plan(PlanController::class, $method, new PatternParser()->parse($pattern), $pattern);
    }
}
