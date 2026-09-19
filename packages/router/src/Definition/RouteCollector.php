<?php

declare(strict_types=1);

namespace Trunk\Router\Definition;

use Closure;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Pattern\PatternParser;

/**
 * Fluent route registration. An instance is handed to modules; there is no static entry point.
 *
 * @api
 */
final class RouteCollector
{
    private const array ANY = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    /** @var list<Route> */
    private array $routes = [];
    /** @internal wired by the container, not part of the API */

    public function __construct(
        private readonly string $prefix = '',
        private readonly PatternParser $parser = new PatternParser(),
        /** @var list<string> */
        private readonly array $groupMiddleware = [],
    ) {}

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function get(string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->map(['GET'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function post(string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->map(['POST'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function put(string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->map(['PUT'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function patch(string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->map(['PATCH'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function delete(string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->map(['DELETE'], $pattern, $handler, $name, $middleware);
    }

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function any(string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $this->map(self::ANY, $pattern, $handler, $name, $middleware);
    }

    /**
     * @param list<string>                               $methods
     * @param array{0: string, 1: string}|string|Closure $handler
     * @param list<string>                         $middleware container ids of PSR-15 middleware for this route
     */
    public function map(array $methods, string $pattern, array|string|Closure $handler, ?string $name = null, array $middleware = []): void
    {
        $full = $this->join($pattern);
        $this->parser->parse($full);
        $this->routes[] = new Route($methods, $full, $handler, $name, [...$this->groupMiddleware, ...$middleware]);
    }

    /**
     * @param Closure(RouteCollector): void $routes
     * @param list<string>            $middleware applied to every route in the group, before the route's own
     */
    public function group(string $prefix, Closure $routes, array $middleware = []): void
    {
        if ($prefix === '' || $prefix[0] !== '/' || str_ends_with($prefix, '/')) {
            throw new InvalidRouteException(\sprintf('Group prefix "%s" must start with "/" and must not end with "/".', $prefix));
        }

        $child = new self($this->prefix . $prefix, $this->parser, [...$this->groupMiddleware, ...$middleware]);
        $routes($child);
        $this->routes = [...$this->routes, ...$child->routes];
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    private function join(string $pattern): string
    {
        if ($this->prefix === '') {
            return $pattern;
        }

        return $pattern === '/' ? $this->prefix : $this->prefix . $pattern;
    }
}
