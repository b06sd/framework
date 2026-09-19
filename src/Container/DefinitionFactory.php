<?php

declare(strict_types=1);

namespace Trunk\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Trunk\Container\Definition\AliasDefinition;
use Trunk\Container\Definition\Argument;
use Trunk\Container\Definition\ConfigValue;
use Trunk\Container\Definition\Definition;
use Trunk\Container\Definition\FactoryDefinition;
use Trunk\Container\Definition\Reference;
use Trunk\Container\Definition\ServiceDefinition;
use Trunk\Container\Definition\TaggedReference;
use Trunk\Container\Definition\Value;
use Trunk\Container\Exception\ContainerException;
use Trunk\Foundation\Configuration;

/**
 * Development counterpart of the compiler: turns a declarative definition into a closure plus its
 * lifetime. Compiled containers contain the equivalent generated code instead.
 */
final readonly class DefinitionFactory
{
    /**
     * @param array<string, list<string>> $tags
     */
    public function __construct(private array $tags = []) {}

    /**
     * @return array{Closure(ContainerInterface): mixed, Lifetime}
     */
    public function entry(Definition $definition): array
    {
        return match (true) {
            // An alias is never cached itself; the target's lifetime applies.
            $definition instanceof AliasDefinition => [static fn(ContainerInterface $c): mixed => $c->get($definition->target), Lifetime::Transient],
            $definition instanceof ServiceDefinition => [fn(ContainerInterface $c): object => new ($definition->class)(...$this->arguments($definition->arguments, $c)), $definition->lifetime],
            $definition instanceof FactoryDefinition => [fn(ContainerInterface $c): mixed => $this->callFactory($definition, $c), $definition->lifetime],
            default => throw new ContainerException('Unknown definition type ' . $definition::class),
        };
    }

    private function callFactory(FactoryDefinition $definition, ContainerInterface $container): mixed
    {
        $factory = $container->get($definition->factory);

        if (!\is_object($factory)) {
            throw new ContainerException(\sprintf('Factory "%s" did not resolve to an object.', $definition->factory));
        }

        return $factory->{$definition->method}(...$this->arguments($definition->arguments, $container));
    }

    /**
     * @param list<Argument> $arguments
     *
     * @return list<mixed>
     */
    private function arguments(array $arguments, ContainerInterface $container): array
    {
        return array_map(fn(Argument $a): mixed => $this->argument($a, $container), $arguments);
    }

    private function argument(Argument $argument, ContainerInterface $container): mixed
    {
        return match (true) {
            $argument instanceof Reference => $container->get($argument->id),
            $argument instanceof Value => $argument->value,
            $argument instanceof TaggedReference => array_map(static fn(string $id): mixed => $container->get($id), $this->tags[$argument->tag] ?? []),
            $argument instanceof ConfigValue => $this->config($argument, $container),
            default => throw new ContainerException('Unknown argument type ' . $argument::class),
        };
    }

    private function config(ConfigValue $argument, ContainerInterface $container): mixed
    {
        $configuration = $container->get(Configuration::class);

        if (!$configuration instanceof Configuration) {
            throw new ContainerException('Configuration is not available in the container.');
        }

        return match ($argument->type) {
            'string' => $configuration->string($argument->key),
            'int' => $configuration->int($argument->key),
            'bool' => $configuration->bool($argument->key),
            'array' => $configuration->array($argument->key),
            default => $configuration->value($argument->key),
        };
    }
}
