<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

use ReflectionException;
use ReflectionMethod;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Lifetime;
use Trunk\Support\ClassName;

/**
 * "Create `$id` by calling `$factory->$method(...$arguments)`", where the factory object itself is
 * resolved from the container. The compilable alternative to a closure.
 */
final readonly class FactoryDefinition implements Definition
{
    /**
     * @param list<Argument> $arguments
     */
    public function __construct(
        public string $id,
        public string $factory,
        public string $method,
        public array $arguments = [],
        public Lifetime $lifetime = Lifetime::Singleton,
    ) {
        if (!ClassName::isValid($factory) || !class_exists($factory)) {
            throw new ContainerException(\sprintf('Cannot define "%s": factory class "%s" does not exist.', $id, $factory));
        }

        try {
            $reflection = new ReflectionMethod($factory, $method);
        } catch (ReflectionException) {
            throw new ContainerException(\sprintf('Cannot define "%s": %s::%s() does not exist.', $id, $factory, $method));
        }

        if (!$reflection->isPublic() || $reflection->isStatic()) {
            throw new ContainerException(\sprintf('Cannot define "%s": %s::%s() must be a public instance method.', $id, $factory, $method));
        }
    }
}
