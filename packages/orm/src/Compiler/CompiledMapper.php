<?php

declare(strict_types=1);

namespace Trunk\Orm\Compiler;

use Closure;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Mapping\Mapper;

/**
 * Wraps the closures generated into build/orm.php. No reflection, no per-row type sniffing.
 */
final readonly class CompiledMapper implements Mapper
{
    /**
     * @param class-string                          $class
     * @param Closure(array<string, mixed>): object $hydrate
     * @param Closure(object): array<string, string|int|float|bool|null> $extract
     * @param Closure(object, int|string): void     $assign
     */
    public function __construct(
        private string $class,
        private Closure $hydrate,
        private Closure $extract,
        private Closure $assign,
    ) {}

    public function hydrate(array $row): object
    {
        return ($this->hydrate)($row);
    }

    public function extract(object $entity): array
    {
        if (!$entity instanceof $this->class) {
            throw new OrmException(\sprintf('Expected %s, got %s.', $this->class, $entity::class));
        }

        return ($this->extract)($entity);
    }

    public function assignId(object $entity, int|string $id): void
    {
        if (!$entity instanceof $this->class) {
            throw new OrmException(\sprintf('Expected %s, got %s.', $this->class, $entity::class));
        }

        ($this->assign)($entity, $id);
    }
}
