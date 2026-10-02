<?php

declare(strict_types=1);

namespace Trunk\Orm\UnitOfWork;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Everything one flush wrote, in the order it was written: inserts, then updates, then deletes.
 *
 * @implements IteratorAggregate<int, Change>
 *
 * @api
 */
final readonly class Changes implements IteratorAggregate, Countable
{
    /**
     * @param list<Change> $changes
     */
    public function __construct(private array $changes) {}

    /**
     * @return list<Change>
     */
    public function all(): array
    {
        return $this->changes;
    }

    /**
     * @param class-string $class
     *
     * @return list<Change> the changes to entities of that class
     */
    public function of(string $class): array
    {
        return array_values(array_filter($this->changes, static fn(Change $change): bool => $change->class === $class));
    }

    public function count(): int
    {
        return \count($this->changes);
    }

    /**
     * @return Traversable<int, Change>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->changes);
    }
}
