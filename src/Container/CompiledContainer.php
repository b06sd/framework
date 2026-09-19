<?php

declare(strict_types=1);

namespace Trunk\Container;

use Psr\Container\ContainerInterface;
use Trunk\Container\Exception\ContainerException;
use Trunk\Container\Exception\NotFoundException;

/**
 * Base class for generated containers. The generated subclass supplies the service table
 * (`lifetimeOf`, `create`) as straight-line PHP, so OPcache can keep it hot and nothing is reflected.
 *
 * @internal an implementation detail, never a base class for application code
 */
abstract class CompiledContainer implements ContainerInterface, Scopable
{
    /** Modules this container was compiled from; checked against the manifest at startup. */
    public const array MODULES = [];

    /** @var array<string, mixed> */
    private array $resolved = [];

    /** @var list<string> */
    private array $resolving = [];

    /**
     * @param array<string, mixed> $instances services supplied at runtime (Runtime, Configuration, or the request in a scope)
     */
    final public function __construct(
        private readonly array $instances = [],
        private readonly ?self $root = null,
    ) {}

    final public function beginScope(array $instances = []): ContainerInterface
    {
        return new static([...$this->instances, ...$instances], $this->root ?? $this);
    }

    final public function has(string $id): bool
    {
        return $id === Scopable::class || \array_key_exists($id, $this->instances) || $this->lifetimeOf($id) !== null;
    }

    final public function get(string $id): mixed
    {
        // The container offers itself, but only as the narrow scope factory (never as a service locator).
        if ($id === Scopable::class) {
            return $this->root ?? $this;
        }

        if (\array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        return match ($this->lifetimeOf($id) ?? throw new NotFoundException(\sprintf('No entry was found for "%s".', $id))) {
            Lifetime::Transient => $this->build($id),
            Lifetime::Singleton => $this->root !== null ? $this->root->get($id) : $this->cached($id),
            Lifetime::Scoped => $this->root !== null
                ? $this->cached($id)
                : throw new ContainerException(\sprintf('Scoped service "%s" was requested outside a scope (or by a singleton). Open a scope with beginScope().', $id)),
        };
    }

    /**
     * @return Lifetime|null null when the id is not defined
     */
    abstract protected function lifetimeOf(string $id): ?Lifetime;

    abstract protected function create(string $id): mixed;

    private function cached(string $id): mixed
    {
        return \array_key_exists($id, $this->resolved) ? $this->resolved[$id] : $this->resolved[$id] = $this->build($id);
    }

    private function build(string $id): mixed
    {
        if (\in_array($id, $this->resolving, true)) {
            throw new ContainerException(\sprintf('Circular dependency: %s.', implode(' -> ', [...$this->resolving, $id])));
        }

        $this->resolving[] = $id;

        try {
            return $this->create($id);
        } finally {
            array_pop($this->resolving);
        }
    }
}
