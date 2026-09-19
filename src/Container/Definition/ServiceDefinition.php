<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

use ReflectionClass;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Lifetime;
use Trunk\Support\ClassName;

/**
 * "Create `$id` with `new $class(...$arguments)`". Declarative, so it can be compiled.
 */
final readonly class ServiceDefinition implements Definition
{
    /** @var class-string */
    public string $class;

    /**
     * @param list<Argument> $arguments
     */
    public function __construct(
        public string $id,
        string $class,
        public array $arguments = [],
        public Lifetime $lifetime = Lifetime::Singleton,
    ) {
        if (!ClassName::isValid($class) || !class_exists($class)) {
            throw new ContainerException(\sprintf('Cannot define "%s": class "%s" does not exist.', $id, $class));
        }

        $reflection = new ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(\sprintf('Cannot define "%s": class "%s" is not instantiable.', $id, $class));
        }

        $this->class = $reflection->getName();
    }
}
