<?php

declare(strict_types=1);

namespace Trunk\Http\Routing;

use Psr\Http\Server\MiddlewareInterface;
use Trunk\Router\Compiler\RouteTable;
use Trunk\Support\ClassName;

/**
 * Checks the middleware named by routes and groups: each must be a real PSR-15 middleware class.
 * Used by the build and by development startup, so a typo fails fast with the route it belongs to.
 */
final class RouteMiddlewareValidator
{
    /**
     * @return list<string>
     */
    public function errors(RouteTable $table): array
    {
        $errors = [];

        foreach ($table->routes as $route) {
            foreach ($route['middleware'] as $class) {
                if (!ClassName::isValid($class) || !class_exists($class)) {
                    $errors[] = \sprintf('Route %s: middleware "%s" does not exist. Fix: check the class name and `composer dump-autoload`.', $route['pattern'], $class);
                } elseif (!is_subclass_of($class, MiddlewareInterface::class)) {
                    $errors[] = \sprintf('Route %s: %s must implement %s.', $route['pattern'], $class, MiddlewareInterface::class);
                }
            }
        }

        return $errors;
    }
}
