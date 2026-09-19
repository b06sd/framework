<?php

declare(strict_types=1);

namespace Trunk\Router\Definition;

use Closure;
use ReflectionException;
use ReflectionMethod;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Pattern\PatternParser;
use Trunk\Support\ClassName;

/**
 * @phpstan-import-type ArgumentPlan from ArgumentPlanner
 *
 * A validated route definition. Handlers are `[Controller::class, 'method']` or an invokable
 * class-string; closures are rejected because they cannot be compiled.
 */
final readonly class Route
{
    /** @var list<string> */
    public array $methods;

    /** @var array{class-string, string} */
    public array $handler;

    /** @var list<ArgumentPlan> */
    public array $arguments;

    /**
     * @param list<string>                                 $methods
     * @param list<string>                           $middleware container ids of PSR-15 middleware that run after routing, before the handler
     * @param array{0: string, 1: string}|string|Closure   $handler
     */
    public function __construct(
        array $methods,
        public string $pattern,
        array|string|Closure $handler,
        public ?string $name = null,
        public array $middleware = [],
    ) {
        if ($methods === []) {
            throw new InvalidRouteException(\sprintf('Route "%s" needs at least one HTTP method.', $pattern));
        }

        foreach ($methods as $method) {
            if (preg_match('/^[A-Z]+$/D', $method) !== 1) {
                throw new InvalidRouteException(\sprintf('"%s" is not a valid HTTP method (use upper-case letters).', $method));
            }
        }

        if ($name !== null && preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1) {
            throw new InvalidRouteException(\sprintf('"%s" is not a valid route name.', $name));
        }

        foreach ($middleware as $id) {
            if (!ClassName::isValid($id)) {
                throw new InvalidRouteException(\sprintf('Route "%s": "%s" is not a middleware class name.', $pattern, substr(preg_replace('/[^A-Za-z0-9_\\\\]/', '?', $id) ?? '', 0, 60)));
            }
        }

        $this->methods = array_values(array_unique($methods));
        $this->handler = self::normalizeHandler($handler, $pattern);
        $this->arguments = new ArgumentPlanner()->plan($this->handler[0], $this->handler[1], new PatternParser()->parse($pattern), $pattern);
    }

    /**
     * @param array{0: string, 1: string}|string|Closure $handler
     *
     * @return array{class-string, string}
     */
    private static function normalizeHandler(array|string|Closure $handler, string $pattern): array
    {
        if ($handler instanceof Closure) {
            throw new InvalidRouteException(\sprintf('Route "%s": closures cannot be compiled; use [Controller::class, \'method\'].', $pattern));
        }

        [$class, $method] = \is_string($handler) ? [$handler, '__invoke'] : $handler;

        if (!ClassName::isValid($class) || !class_exists($class)) {
            throw new InvalidRouteException(\sprintf('Route "%s": handler class "%s" does not exist.', $pattern, $class));
        }

        try {
            $reflection = new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            throw new InvalidRouteException(\sprintf('Route "%s": %s::%s() does not exist.', $pattern, $class, $method));
        }

        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new InvalidRouteException(\sprintf('Route "%s": %s::%s() must be a public instance method.', $pattern, $class, $method));
        }

        return [$class, $method];
    }
}
