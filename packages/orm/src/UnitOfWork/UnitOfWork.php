<?php

declare(strict_types=1);

namespace Trunk\Orm\UnitOfWork;

use Closure;
use DateTimeImmutable;
use SplObjectStorage;
use Trunk\Database\Connection\Connection;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\StaleEntity;
use Trunk\Orm\Mapping\Convert;
use Trunk\Orm\Mapping\EntityMetadata;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\Mapping\Type;
use WeakMap;

/**
 * Tracks the entities of one request or job: an identity map (one object per row), a snapshot of
 * each loaded row for dirty checking, and the pending inserts and removals. flush() writes
 * everything in a single transaction, updating only the columns that changed. All SQL goes
 * through the query builder, so every value is a binding.
 *
 * Request/job scoped by design: never share one across requests.
 */
final class UnitOfWork
{
    /** @var array<class-string, array<string, object>> */
    private array $identity = [];

    /** @var SplObjectStorage<object, array{snapshot: array<string, string|int|float|bool|null>, version: int|null}> */
    private SplObjectStorage $managed;

    /** @var SplObjectStorage<object, true> */
    private SplObjectStorage $new;

    /** @var SplObjectStorage<object, true> */
    private SplObjectStorage $removed;

    /** @var WeakMap<object, array<string, mixed>> */
    private WeakMap $relations;

    /**
     * @param Closure(): DateTimeImmutable $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MappingRegistry $registry,
        private readonly Closure $clock,
    ) {
        $this->managed = new SplObjectStorage();
        $this->new = new SplObjectStorage();
        $this->removed = new SplObjectStorage();
        $this->relations = new WeakMap();
    }

    /**
     * @param class-string $class
     */
    public function find(string $class, int|string $id): ?object
    {
        return $this->identity[$class][(string) $id] ?? null;
    }

    /**
     * Registers a freshly loaded entity. Returns the entity already managed for that row if there is one.
     *
     * @param array<string, string|int|float|bool|null> $snapshot
     */
    public function manage(object $entity, EntityMetadata $metadata, int|string $id, array $snapshot): object
    {
        $existing = $this->identity[$metadata->class][(string) $id] ?? null;

        if ($existing !== null) {
            return $existing;
        }

        $versionColumn = $metadata->versionColumn();
        $version = $versionColumn === null ? null : $snapshot[$versionColumn->column] ?? null;
        $this->identity[$metadata->class][(string) $id] = $entity;
        $this->managed[$entity] = ['snapshot' => $snapshot, 'version' => \is_int($version) ? $version : null];

        return $entity;
    }

    public function isManaged(object $entity): bool
    {
        return isset($this->managed[$entity]);
    }

    /**
     * @return array<string, string|int|float|bool|null>|null
     */
    public function snapshot(object $entity): ?array
    {
        return isset($this->managed[$entity]) ? $this->managed[$entity]['snapshot'] : null;
    }

    public function persist(object $entity): void
    {
        $this->registry->metadata($entity::class);

        if (isset($this->managed[$entity]) || isset($this->new[$entity])) {
            return;
        }

        $this->new[$entity] = true;
    }

    public function remove(object $entity): void
    {
        if (isset($this->new[$entity])) {
            unset($this->new[$entity]);

            return;
        }

        if (!isset($this->managed[$entity])) {
            throw new OrmException(\sprintf('This %s is not managed by the unit of work (it was not loaded through a repository or persisted).', $entity::class));
        }

        $this->removed[$entity] = true;
    }

    public function setRelation(object $entity, string $name, mixed $value): void
    {
        $loaded = $this->relations[$entity] ?? [];
        $loaded[$name] = $value;
        $this->relations[$entity] = $loaded;
    }

    public function hasRelation(object $entity, string $name): bool
    {
        return \array_key_exists($name, $this->relations[$entity] ?? []);
    }

    public function relation(object $entity, string $name): mixed
    {
        return ($this->relations[$entity] ?? [])[$name] ?? null;
    }

    public function hasChanges(): bool
    {
        return \count($this->new) > 0 || \count($this->removed) > 0 || $this->dirty() !== [];
    }

    /**
     * Writes all pending changes atomically. If the transaction fails nothing is written; discard
     * the unit of work (clear()) after a failure rather than reusing it.
     */
    public function flush(): void
    {
        $inserts = iterator_to_array($this->new, false);
        $updates = $this->dirty();
        $deletes = iterator_to_array($this->removed, false);

        if ($inserts === [] && $updates === [] && $deletes === []) {
            return;
        }

        $inserted = [];
        $updated = [];

        $this->connection->transaction(function () use ($inserts, $updates, $deletes, &$inserted, &$updated): void {
            $this->insertAll($inserts, $inserted);

            foreach ($updates as [$entity, $changes]) {
                $updated[] = $this->update($entity, $changes);
            }

            foreach ($deletes as $entity) {
                $this->delete($entity);
            }
        });

        foreach ($inserted as [$entity, $row]) {
            $metadata = $this->registry->metadata($entity::class);
            $id = $row[$metadata->idColumn()->column] ?? null;

            if (\is_int($id) || \is_string($id)) {
                $this->manage($entity, $metadata, $id, $row);
            }
        }

        foreach ($updated as [$entity, $row, $version]) {
            $this->managed[$entity] = ['snapshot' => $row, 'version' => $version];
        }

        foreach ($deletes as $entity) {
            $this->forget($entity);
        }

        $this->new = new SplObjectStorage();
        $this->removed = new SplObjectStorage();
    }

    /**
     * Forgets everything. Entities already handed out keep working as plain objects.
     */
    public function clear(): void
    {
        $this->identity = [];
        $this->managed = new SplObjectStorage();
        $this->new = new SplObjectStorage();
        $this->removed = new SplObjectStorage();
        $this->relations = new WeakMap();
    }

    /**
     * Inserts in persist order, one statement per run of same-class entities (so foreign-key
     * constraints between classes see the same order the developer persisted in).
     *
     * @param list<object>                                                   $inserts
     * @param list<array{object, array<string, string|int|float|bool|null>}> $inserted
     */
    private function insertAll(array $inserts, array &$inserted): void
    {
        $run = [];
        $runClass = null;

        foreach ($inserts as $entity) {
            if ($run !== [] && $entity::class !== $runClass) {
                $this->insertRun($run, $inserted);
                $run = [];
            }

            $runClass = $entity::class;
            $run[] = $entity;
        }

        if ($run !== []) {
            $this->insertRun($run, $inserted);
        }
    }

    /**
     * @param non-empty-list<object>                                         $entities all of one class
     * @param list<array{object, array<string, string|int|float|bool|null>}> $inserted
     */
    private function insertRun(array $entities, array &$inserted): void
    {
        $metadata = $this->registry->metadata($entities[0]::class);
        $mapper = $this->registry->mapper($metadata->class);
        $idColumn = $metadata->idColumn();
        $rows = array_map($mapper->extract(...), $entities);

        foreach ($rows as $row) {
            $this->assertStorable($row, $metadata->class);
        }
        $query = $this->connection->table($metadata->table);

        if (!$metadata->generatedId) {
            $query->insert($rows);

            foreach ($entities as $i => $entity) {
                $inserted[] = [$entity, $rows[$i]];
            }

            return;
        }

        foreach ($rows as $i => $row) {
            unset($row[$idColumn->column]);
            $rows[$i] = $row;
        }

        $ids = $idColumn->type === Type::Int ? $query->insertGetIds($rows, $idColumn->column) : array_map(fn(array $row): int|string => $query->insertGetId($row, $idColumn->column), $rows);

        foreach ($entities as $i => $entity) {
            $mapper->assignId($entity, $ids[$i]);
            $inserted[] = [$entity, $mapper->extract($entity)];
        }
    }

    /**
     * @return list<array{object, array<string, string|int|float|bool|null>}> entity and its changed columns
     */
    private function dirty(): array
    {
        $dirty = [];

        foreach ($this->managed as $entity) {
            if (isset($this->removed[$entity])) {
                continue;
            }

            $metadata = $this->registry->metadata($entity::class);
            $current = $this->registry->mapper($entity::class)->extract($entity);
            $state = $this->managed[$entity];
            $idColumn = $metadata->idColumn()->column;
            $versionColumn = $metadata->versionColumn()?->column;
            $changes = [];

            foreach ($current as $column => $value) {
                if ($column === $versionColumn) {
                    continue;
                }

                if (($state['snapshot'][$column] ?? null) !== $value) {
                    if ($column === $idColumn) {
                        throw new OrmException(\sprintf('The primary key of a managed %s changed. Primary keys are immutable; remove and persist a new entity instead.', $metadata->class));
                    }

                    $changes[$column] = $value;
                }
            }

            if ($changes !== []) {
                $dirty[] = [$entity, $changes];
            }
        }

        return $dirty;
    }

    /**
     * PostgreSQL silently cuts text at a NUL byte, so a save would store different data from what was
     * given (MySQL and SQLite keep it). On PostgreSQL that is refused instead of corrupting the value.
     *
     * @param array<string, string|int|float|bool|null> $row
     */
    private function assertStorable(array $row, string $class): void
    {
        if ($this->connection->driver()->name() !== 'pgsql') {
            return;
        }

        foreach ($row as $column => $value) {
            if (\is_string($value) && str_contains($value, "\0")) {
                throw new OrmException(\sprintf('%s: the value for "%s" contains a NUL byte. PostgreSQL would silently truncate the text there, so it is refused; remove it before saving.', $class, $column));
            }
        }
    }

    /**
     * @param array<string, string|int|float|bool|null> $changes
     *
     * @return array{object, array<string, string|int|float|bool|null>, int|null}
     */
    private function update(object $entity, array $changes): array
    {
        $metadata = $this->registry->metadata($entity::class);
        $state = $this->managed[$entity];
        $idColumn = $metadata->idColumn()->column;
        $versionColumn = $metadata->versionColumn()?->column;
        $id = $state['snapshot'][$idColumn] ?? null;
        $values = $changes;
        $next = $state['version'];

        if ($versionColumn !== null && $state['version'] !== null) {
            $next = $state['version'] + 1;
            $values[$versionColumn] = $next;
        }

        $query = $this->connection->table($metadata->table)->where($idColumn, $id);

        if ($versionColumn !== null && $state['version'] !== null) {
            $query = $query->where($versionColumn, $state['version']);
        }

        $this->assertStorable($values, $metadata->class);
        $affected = $query->update($values);

        if ($affected === 0 && $versionColumn !== null) {
            throw StaleEntity::for($metadata->class);
        }

        return [$entity, [...$state['snapshot'], ...$values], $next];
    }

    private function delete(object $entity): void
    {
        $metadata = $this->registry->metadata($entity::class);
        $state = $this->managed[$entity];
        $idColumn = $metadata->idColumn()->column;
        $versionColumn = $metadata->versionColumn()?->column;
        $query = $this->connection->table($metadata->table)->where($idColumn, $state['snapshot'][$idColumn] ?? null);

        if ($versionColumn !== null && $state['version'] !== null) {
            $query = $query->where($versionColumn, $state['version']);
        }

        $softDelete = $metadata->softDeleteColumn();

        if ($softDelete !== null) {
            $values = [$softDelete->column => Convert::dateTimeToDb(($this->clock)())];

            if ($versionColumn !== null && $state['version'] !== null) {
                $values[$versionColumn] = $state['version'] + 1;
            }

            $affected = $query->update($values);
        } else {
            $affected = $query->delete();
        }

        if ($affected === 0 && $versionColumn !== null) {
            throw StaleEntity::for($metadata->class);
        }
    }

    private function forget(object $entity): void
    {
        $metadata = $this->registry->metadata($entity::class);
        $id = $this->managed[$entity]['snapshot'][$metadata->idColumn()->column] ?? null;
        unset($this->managed[$entity]);

        if (\is_int($id) || \is_string($id)) {
            unset($this->identity[$metadata->class][(string) $id]);
        }
    }
}
