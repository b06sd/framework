<?php

declare(strict_types=1);

namespace Trunk\Container;

use ReflectionClass;
use ReflectionNamedType;
use Trunk\Container\Definition\Argument;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\ServiceDefinition;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Support\ClassName;

/**
 * Turns a constructor signature into a declarative definition. Runs while modules register (and in
 * development on demand), never in the compiled container, so production performs no reflection.
 */
final class Autowirer
{
    /**
     * @param array<string, Argument> $with overrides constructor parameters by name
     */
    public function definitionFor(string $class, Lifetime $lifetime = Lifetime::Singleton, array $with = []): ServiceDefinition
    {
        if (!ClassName::isValid($class) || !class_exists($class)) {
            throw new ContainerException(\sprintf('Cannot autowire "%s": class does not exist.', $class));
        }

        $arguments = [];
        $used = [];

        foreach (new ReflectionClass($class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            if (isset($with[$name])) {
                $arguments[] = $with[$name];
                $used[$name] = true;
            } elseif ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $arguments[] = new Reference($type->getName(), $class . '::__construct($' . $name . ')');
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = new Value($parameter->getDefaultValue());
            } else {
                throw new ContainerException(\sprintf(
                    'Cannot autowire "%s": parameter $%s has no class type and no default. Fix: $builder->autowire(%s::class, with: [\'%s\' => new ConfigValue(\'your.config.key\')]); or use ->service().',
                    $class,
                    $name,
                    $class,
                    $name,
                ));
            }
        }

        $unknown = array_diff(array_keys($with), array_keys($used));

        if ($unknown !== []) {
            throw new ContainerException(\sprintf('Cannot autowire "%s": it has no constructor parameter named $%s.', $class, (string) reset($unknown)));
        }

        return new ServiceDefinition($class, $class, $arguments, $lifetime);
    }
}
