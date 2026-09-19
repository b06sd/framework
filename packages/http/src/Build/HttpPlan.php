<?php

declare(strict_types=1);

namespace Trunk\Http\Build;

use Trunk\Router\Compiler\RouteTable;

/**
 * The validated HTTP part of a build: compiled routes and the global middleware list.
 */
final readonly class HttpPlan
{
    /**
     * @param list<string> $middleware
     */
    public function __construct(public RouteTable $routes, public array $middleware) {}

    /**
     * Controllers and middleware are the roots of the dependency graph.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $controllers = array_map(static fn(array $route): string => $route['handler'][0], $this->routes->routes);

        $routeMiddleware = array_merge(...array_map(static fn(array $route): array => $route['middleware'], $this->routes->routes));

        return array_values(array_unique([...$controllers, ...$this->middleware, ...$routeMiddleware]));
    }
}
