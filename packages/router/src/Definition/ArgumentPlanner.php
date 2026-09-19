<?php

declare(strict_types=1);

namespace Trunk\Router\Definition;

use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use ReflectionNamedType;
use Trunk\Router\Exception\InvalidRouteException;
use Trunk\Router\Pattern\ParsedPattern;

/**
 * Build-time only: inspects a controller method once and produces a plain-array plan that the
 * runtime can execute without any reflection.
 *
 * @phpstan-type ArgumentPlan array{kind: string, name: string, type: string, nullable: bool, hasDefault: bool, default: scalar|null}
 */
final class ArgumentPlanner
{
    private const array SCALARS = ['int', 'float', 'bool', 'string'];

    /**
     * @return list<ArgumentPlan>
     */
    public function plan(string $class, string $method, ParsedPattern $pattern, string $routePattern): array
    {
        $optional = [];

        foreach ($pattern->segments as $segment) {
            if ($segment->param !== null) {
                $optional[$segment->param] = $segment->optional;
            }
        }

        $plan = [];

        foreach (new ReflectionMethod($class, $method)->getParameters() as $parameter) {
            $where = \sprintf('Route "%s": %s::%s() parameter $%s', $routePattern, $class, $method, $parameter->getName());
            $name = $parameter->getName();
            $type = $parameter->getType();
            $namedType = $type instanceof ReflectionNamedType ? $type : null;
            $hasDefault = $parameter->isDefaultValueAvailable();
            $default = $hasDefault ? $parameter->getDefaultValue() : null;

            if ($parameter->isVariadic()) {
                throw new InvalidRouteException($where . ' cannot be variadic.');
            }

            if ($default !== null && !\is_scalar($default)) {
                throw new InvalidRouteException($where . ' has a default that is not a scalar or null.');
            }

            if ($namedType !== null && !$namedType->isBuiltin() && $namedType->getName() === ServerRequestInterface::class) {
                $plan[] = ['kind' => ArgumentKind::Request->value, 'name' => $name, 'type' => 'request', 'nullable' => false, 'hasDefault' => false, 'default' => null];

                continue;
            }

            if (isset($optional[$name])) {
                if ($namedType === null || !$namedType->isBuiltin() || !\in_array($namedType->getName(), self::SCALARS, true)) {
                    throw new InvalidRouteException($where . ' must be typed int, float, bool or string to receive a route parameter.');
                }

                if ($optional[$name] && !$hasDefault && !$namedType->allowsNull()) {
                    throw new InvalidRouteException($where . ' receives an optional route parameter and needs a default or a nullable type.');
                }

                $plan[] = [
                    'kind' => ArgumentKind::Param->value,
                    'name' => $name,
                    'type' => $namedType->getName(),
                    'nullable' => $namedType->allowsNull(),
                    'hasDefault' => $hasDefault,
                    'default' => $default,
                ];

                continue;
            }

            if ($hasDefault) {
                $plan[] = ['kind' => ArgumentKind::Default->value, 'name' => $name, 'type' => 'default', 'nullable' => true, 'hasDefault' => true, 'default' => $default];

                continue;
            }

            throw new InvalidRouteException($where . ' cannot be bound: it is neither the request, a route parameter nor has a default.');
        }

        return $plan;
    }
}
