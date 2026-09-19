<?php

declare(strict_types=1);

namespace Trunk\Tests\Unit\Http\Routing;

use LogicException;
use PHPUnit\Framework\TestCase;
use Trunk\Container\ContainerBuilder;
use Trunk\Http\Factory\HttpFactory;
use Trunk\Http\Message\ServerRequest;
use Trunk\Http\Routing\ControllerDispatcher;
use Trunk\Http\Routing\RoutingMiddleware;
use Trunk\Router\Matcher\MatchResult;
use Trunk\Tests\Fixtures\Controllers\HomeController;
use Trunk\Tests\Fixtures\Controllers\PageController;

final class ControllerDispatcherTest extends TestCase
{
    public function test_a_request_without_a_matched_route_is_a_programming_error(): void
    {
        // Arrange
        $dispatcher = new ControllerDispatcher(new ContainerBuilder()->build());

        // Act & Assert
        $this->expectException(LogicException::class);
        $dispatcher->handle(new ServerRequest('GET', '/'));
    }

    public function test_a_controller_returning_something_other_than_a_response_is_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance(PageController::class, new PageController(new HttpFactory()));
        $request = new ServerRequest('GET', '/')->withAttribute(RoutingMiddleware::ATTRIBUTE, MatchResult::found(0, [PageController::class, 'bad'], null, []));

        // Act & Assert
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return a');
        new ControllerDispatcher($builder->build())->handle($request);
    }

    public function test_a_container_entry_lacking_the_method_is_rejected(): void
    {
        // Arrange
        $builder = new ContainerBuilder();
        $builder->instance(PageController::class, new HomeController());
        $request = new ServerRequest('GET', '/')->withAttribute(RoutingMiddleware::ATTRIBUTE, MatchResult::found(0, [PageController::class, 'show'], null, []));

        // Act & Assert
        $this->expectException(LogicException::class);
        new ControllerDispatcher($builder->build())->handle($request);
    }
}
