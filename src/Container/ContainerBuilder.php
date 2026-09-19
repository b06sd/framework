<?php

declare(strict_types=1);

namespace Trunk\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Trunk\Container\Definition\AliasDefinition;
use Trunk\Container\Definition\Argument;
use Trunk\Container\Definition\Definition;
use Trunk\Container\Definition\FactoryDefinition;
use Trunk\Container\Definition\ServiceDefinition;
use Trunk\Container\Exception\ContainerException;

/**
 * Collects explicit bindings; `build()` produces a read-only container.
 *
 * Declarative bindings (`bind`, `singleton`, `scoped`, `transient`, `autowire`, `service`, `factory`,
 * `alias`, `tag`) can be compiled. Closure bindings (`closure`, `instance`) work in development but
 * make a production build fail.
 *
 * @api
 */
final class ContainerBuilder
{
    /** @var array<string, array{Closure(ContainerInterface): mixed, Lifetime}> */
    private array $closures = [];

    /** @var array<string, Definition> */
    private array $definitions = [];

    /** @var array<string, list<string>> */
    private array $tags = [];

    /** @var list<string> */
    private array $scopeProvided = [];

    /** @var array<string, array{string, Lifetime}> */
    private array $defaults = [];
    /** @internal wired by the container, not part of the API */

    public function __construct(private readonly Autowirer $autowirer = new Autowirer()) {}

    /**
     * Binds an interface (or abstract class) to an implementation, which is autowired.
     */
    public function bind(string $abstract, string $implementation, Lifetime $lifetime = Lifetime::Singleton): void
    {
        if (!$this->has($implementation)) {
            $this->autowire($implementation, $lifetime);
        }

        $this->alias($abstract, $implementation);
    }

    /**
     * Binds `$id` to `$implementation` only if no module binds `$id` itself. A later module (an optional
     * package) can therefore replace a core default just by binding the same id, whatever the module
     * order. This is how Trunk's null logger/tracer/metrics defaults are swapped for real ones.
     */
    public function bindDefault(string $id, string $implementation, Lifetime $lifetime = Lifetime::Singleton): void
    {
        $this->defaults[$id] = [$implementation, $lifetime];
    }

    /**
     * Registers a class, or binds an interface to an implementation, as a singleton.
     */
    public function singleton(string $class, ?string $implementation = null): void
    {
        $this->register($class, $implementation, Lifetime::Singleton);
    }

    /**
     * One instance per scope (per HTTP request).
     */
    public function scoped(string $class, ?string $implementation = null): void
    {
        $this->register($class, $implementation, Lifetime::Scoped);
    }

    /**
     * A new instance every time it is resolved.
     */
    public function transient(string $class, ?string $implementation = null): void
    {
        $this->register($class, $implementation, Lifetime::Transient);
    }

    /**
     * Registers `$class` under its own name, resolving constructor parameters from their types.
     *
     * @param array<string, Argument> $with overrides constructor parameters by name
     */
    public function autowire(string $class, Lifetime $lifetime = Lifetime::Singleton, array $with = []): void
    {
        $definition = $this->autowirer->definitionFor($class, $lifetime, $with);
        $this->assertFree($definition->id);
        $this->definitions[$definition->id] = $definition;
    }

    /**
     * @param list<Argument> $arguments
     */
    public function service(string $id, string $class, array $arguments = [], Lifetime $lifetime = Lifetime::Singleton): void
    {
        $definition = new ServiceDefinition($id, $class, $arguments, $lifetime);
        $this->assertFree($id);
        $this->definitions[$id] = $definition;
    }

    /**
     * Creates `$id` by calling `$factory[1]()` on the container's `$factory[0]` service.
     *
     * @param array{string, string} $factory class and public instance method
     * @param list<Argument>        $arguments
     */
    public function factory(string $id, array $factory, array $arguments = [], Lifetime $lifetime = Lifetime::Singleton): void
    {
        $definition = new FactoryDefinition($id, $factory[0], $factory[1], $arguments, $lifetime);
        $this->assertFree($id);
        $this->definitions[$id] = $definition;
    }

    public function alias(string $id, string $target): void
    {
        $this->assertFree($id);
        $this->definitions[$id] = new AliasDefinition($id, $target);
    }

    /**
     * Tags services so they can be injected together with a TaggedReference.
     */
    public function tag(string $tag, string ...$ids): void
    {
        if (preg_match('/^[A-Za-z0-9_.\-]+$/D', $tag) !== 1) {
            throw new ContainerException(\sprintf('"%s" is not a valid tag name.', $tag));
        }

        foreach ($ids as $id) {
            $this->tags[$tag][] = $id;
        }
    }

    /**
     * Development-only: a closure cannot be compiled, so a production build refuses it.
     *
     * @param Closure(ContainerInterface): mixed $factory
     */
    public function closure(string $id, Closure $factory, Lifetime $lifetime = Lifetime::Singleton): void
    {
        $this->assertFree($id);
        $this->closures[$id] = [$factory, $lifetime];
    }

    /**
     * Development-only, like `closure()`.
     */
    public function instance(string $id, mixed $value): void
    {
        $this->closure($id, static fn(): mixed => $value);
    }

    public function has(string $id): bool
    {
        return isset($this->closures[$id]) || isset($this->definitions[$id]);
    }

    /**
     * Declares ids that the host supplies inside every scope (for example the current HTTP request),
     * so the compiler treats them as scoped services when checking lifetimes.
     */
    public function providedInScope(string ...$ids): void
    {
        foreach ($ids as $id) {
            $this->scopeProvided[] = $id;
        }
    }

    /**
     * @return list<string>
     */
    public function scopedProvided(): array
    {
        return array_values(array_unique($this->scopeProvided));
    }

    /**
     * @return array<string, Definition>
     */
    public function definitions(): array
    {
        $definitions = $this->definitions;

        foreach ($this->defaults as $id => [$implementation, $lifetime]) {
            if (isset($definitions[$id]) || isset($this->closures[$id])) {
                continue;
            }

            $definitions[$implementation] ??= $this->autowirer->definitionFor($implementation, $lifetime);
            $definitions[$id] = new AliasDefinition($id, $implementation);
        }

        return $definitions;
    }

    /**
     * @return array<string, list<string>>
     */
    public function tags(): array
    {
        return $this->tags;
    }

    /**
     * @return list<string>
     */
    public function closureIds(): array
    {
        return array_map(\strval(...), array_keys($this->closures));
    }
    /** @internal wired by the container, not part of the API */

    public function build(): Container
    {
        $entries = $this->closures;
        $factory = new DefinitionFactory($this->tags);

        foreach ($this->definitions() as $id => $definition) {
            $entries[$id] = $factory->entry($definition);
        }

        return new Container($entries, $factory, $this->autowirer);
    }

    private function register(string $class, ?string $implementation, Lifetime $lifetime): void
    {
        $implementation === null ? $this->autowire($class, $lifetime) : $this->bind($class, $implementation, $lifetime);
    }

    private function assertFree(string $id): void
    {
        // PHP coerces integer-like array keys to int, which would break typed id handling.
        if ($id === '' || preg_match('/^-?\d+$/', $id) === 1) {
            throw new ContainerException(\sprintf('"%s" is not a valid service id.', $id));
        }

        if ($this->has($id)) {
            throw new ContainerException(\sprintf('"%s" is already bound.', $id));
        }
    }
}
