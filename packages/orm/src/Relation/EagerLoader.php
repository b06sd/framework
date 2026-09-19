<?php

declare(strict_types=1);

namespace Trunk\Orm\Relation;

use Trunk\Orm\Mapping\EntityMetadata;
use Trunk\Orm\Mapping\RelationKind;
use Trunk\Orm\Mapping\RelationMetadata;
use Trunk\Orm\UnitOfWork\EntityManager;

/**
 * Loads relations for a set of entities with one query per relation (per 500 keys), never one per
 * entity. Related rows go through the target's own Query, so its soft-delete rule and global
 * scopes apply to them too. Every key column comes from validated metadata.
 */
final readonly class EagerLoader
{
    private const int CHUNK = 500;

    public function __construct(private EntityManager $manager) {}

    /**
     * @param list<object> $entities
     * @param list<string> $paths    e.g. `orders`, `orders.items`
     */
    public function load(array $entities, EntityMetadata $metadata, array $paths): void
    {
        if ($entities === []) {
            return;
        }

        $tree = [];

        foreach ($paths as $path) {
            $segments = explode('.', $path, 2);
            $tree[$segments[0]] ??= [];

            if (isset($segments[1])) {
                $tree[$segments[0]][] = $segments[1];
            }
        }

        foreach ($tree as $name => $nested) {
            $this->relation($entities, $metadata, $metadata->relation($name), $nested);
        }
    }

    /**
     * @param list<object> $entities
     * @param list<string> $nested
     */
    private function relation(array $entities, EntityMetadata $metadata, RelationMetadata $relation, array $nested): void
    {
        match ($relation->kind) {
            RelationKind::HasOne, RelationKind::HasMany => $this->loadOwned($entities, $metadata, $relation, $nested),
            RelationKind::BelongsTo => $this->loadOwner($entities, $metadata, $relation, $nested),
            RelationKind::BelongsToMany => $this->loadPivot($entities, $metadata, $relation, $nested),
        };
    }

    /**
     * @param list<object> $entities
     * @param list<string> $nested
     */
    private function loadOwned(array $entities, EntityMetadata $metadata, RelationMetadata $relation, array $nested): void
    {
        $keys = $this->keys($entities, $metadata, $relation->localColumn);
        $related = $this->fetch($relation, $relation->foreignColumn, $keys, $nested);
        $grouped = [];

        foreach ($related as $entity) {
            $key = $this->column($entity, $relation->foreignColumn);

            if ($key !== null) {
                $grouped[$key][] = $entity;
            }
        }

        foreach ($entities as $entity) {
            $matches = $grouped[$this->column($entity, $relation->localColumn) ?? ''] ?? [];
            $this->manager->unitOfWork()->setRelation($entity, $relation->name, $relation->kind === RelationKind::HasMany ? $matches : ($matches[0] ?? null));
        }
    }

    /**
     * @param list<object> $entities
     * @param list<string> $nested
     */
    private function loadOwner(array $entities, EntityMetadata $metadata, RelationMetadata $relation, array $nested): void
    {
        $keys = $this->keys($entities, $metadata, $relation->foreignColumn);
        $related = $this->fetch($relation, $relation->localColumn, $keys, $nested);
        $byKey = [];

        foreach ($related as $entity) {
            $key = $this->column($entity, $relation->localColumn);

            if ($key !== null) {
                $byKey[$key] = $entity;
            }
        }

        foreach ($entities as $entity) {
            $this->manager->unitOfWork()->setRelation($entity, $relation->name, $byKey[$this->column($entity, $relation->foreignColumn) ?? ''] ?? null);
        }
    }

    /**
     * @param list<object> $entities
     * @param list<string> $nested
     */
    private function loadPivot(array $entities, EntityMetadata $metadata, RelationMetadata $relation, array $nested): void
    {
        $pivotTable = $relation->pivotTable ?? '';
        $pivotLocal = $relation->pivotLocalColumn ?? '';
        $pivotForeign = $relation->pivotForeignColumn ?? '';
        $keys = $this->keys($entities, $metadata, $relation->localColumn);
        $links = [];
        $targetKeys = [];

        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $rows = $this->manager->connection()->table($pivotTable)->select($pivotLocal, $pivotForeign)->whereIn($pivotLocal, $chunk)->get();

            foreach ($rows as $row) {
                $local = $row[$pivotLocal] ?? null;
                $foreign = $row[$pivotForeign] ?? null;

                if ((\is_int($local) || \is_string($local)) && (\is_int($foreign) || \is_string($foreign))) {
                    $links[(string) $local][] = (string) $foreign;
                    $targetKeys[(string) $foreign] = $foreign;
                }
            }
        }

        $related = $this->fetch($relation, $relation->foreignColumn, array_values($targetKeys), $nested);
        $byKey = [];

        foreach ($related as $entity) {
            $key = $this->column($entity, $relation->foreignColumn);

            if ($key !== null) {
                $byKey[$key] = $entity;
            }
        }

        foreach ($entities as $entity) {
            $own = $this->column($entity, $relation->localColumn) ?? '';
            $matches = [];

            foreach ($links[$own] ?? [] as $foreign) {
                if (isset($byKey[$foreign])) {
                    $matches[] = $byKey[$foreign];
                }
            }

            $this->manager->unitOfWork()->setRelation($entity, $relation->name, $matches);
        }
    }

    /**
     * @param list<int|string> $keys
     * @param list<string>     $nested
     *
     * @return list<object>
     */
    private function fetch(RelationMetadata $relation, string $column, array $keys, array $nested): array
    {
        $found = [];

        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $query = $this->manager->repository($relation->target)->query()->whereColumnIn($column, $chunk);

            if ($nested !== []) {
                $query = $query->with(...$nested);
            }

            array_push($found, ...$query->get());
        }

        return $found;
    }

    /**
     * @param list<object> $entities
     *
     * @return list<int|string>
     */
    private function keys(array $entities, EntityMetadata $metadata, string $column): array
    {
        $keys = [];

        foreach ($entities as $entity) {
            $value = $this->value($entity, $column);

            if (\is_int($value) || \is_string($value)) {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }

    private function column(object $entity, string $column): ?string
    {
        $value = $this->value($entity, $column);

        return \is_int($value) || \is_string($value) ? (string) $value : null;
    }

    private function value(object $entity, string $column): string|int|float|bool|null
    {
        $row = $this->manager->unitOfWork()->snapshot($entity) ?? $this->manager->registry()->mapper($entity::class)->extract($entity);

        return $row[$column] ?? null;
    }
}
