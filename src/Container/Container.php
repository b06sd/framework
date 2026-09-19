<?php

declare(strict_types=1);

namespace Trunk\Container;

use Closure;
use Psr\Container\ContainerInterface;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Exception\NotFoundException;

/**
 * Development PSR-11 container. Services are created lazily according to their lifetime. Unbound
 * concrete classes are autowired on demand (reflection); the compiled container never does this.
 */
final class Container implements ContainerInterface, Scopable
{
    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var list<string> */
    private array $resolving = [];

    /**
     * @param array<string, array{Closure(ContainerInterface): mixed, Lifetime}> $entries
     * @param array<string, mixed>                                               $instances scope-only services
     */
    public function __construct(
        private array $entries,
        private readonly DefinitionFactory $definitions = new DefinitionFactory(),
        private readonly ?Autowirer $autowirer = null,
        private readonly ?self $root = null,
        private readonly array $instances = [],
    ) {}

    public function beginScope(array $instances = []): ContainerInterface
    {
        return new self($this->entries, $this->definitions, $this->autowirer, $this->root ?? $this, [...$this->instances, ...$instances]);
    }

    public function has(string $id): bool
    {
        return $id === Scopable::class || \array_key_exists($id, $this->instances) || isset($this->entries[$id]);
    }

    public function get(string $id): mixed
    {
        // The container offers itself, but only as the narrow scope factory (never as a service locator).
        if ($id === Scopable::class) {
            return $this->root ?? $this;
        }

        if (\array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->entries[$id]) && !$this->autowire($id)) {
            throw new NotFoundException(new ResolutionHints()->unresolved($id, [...$this->resolving, $id]));
        }

        [$factory, $lifetime] = $this->entries[$id];

        return match ($lifetime) {
            Lifetime::Transient => $this->create($id, $factory),
            Lifetime::Singleton => $this->root !== null ? $this->root->get($id) : $this->cached($id, $factory),
            Lifetime::Scoped => $this->scoped($id, $factory),
        };
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    private function scoped(string $id, Closure $factory): mixed
    {
        if ($this->root === null) {
            throw new ContainerException($this->resolving === []
                ? \sprintf('Scoped service "%s" was requested outside a scope. Open one with beginScope().', $id)
                : \sprintf('Scoped service "%s" was requested by a singleton (path: %s). A singleton lives across requests and must not depend on request-scoped services; make the consumer scoped or inject a factory.', $id, implode(' -> ', [...$this->resolving, $id])));
        }

        return $this->cached($id, $factory);
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    private function cached(string $id, Closure $factory): mixed
    {
        return \array_key_exists($id, $this->resolved) ? $this->resolved[$id] : $this->resolved[$id] = $this->create($id, $factory);
    }

    /**
     * @param Closure(ContainerInterface): mixed $factory
     */
    private function create(string $id, Closure $factory): mixed
    {
        if (\in_array($id, $this->resolving, true)) {
            throw new ContainerException(\sprintf('Circular dependency: %s.', implode(' -> ', [...$this->resolving, $id])));
        }

        $this->resolving[] = $id;

        try {
            return $factory($this);
        } finally {
            array_pop($this->resolving);
        }
    }

    /**
     * Development fallback: registers an unbound concrete class by reflecting on its constructor.
     * Inside a scope (i.e. while handling a request) it is registered as scoped, matching how the
     * compiler treats controllers and middleware.
     */
    private function autowire(string $id): bool
    {
        if ($this->autowirer === null || !new ResolutionHints()->isConcrete($id)) {
            return false;
        }

        $this->entries[$id] = $this->definitions->entry($this->autowirer->definitionFor($id, $this->root !== null ? Lifetime::Scoped : Lifetime::Singleton));

        return true;
    }
}
