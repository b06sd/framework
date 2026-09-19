<?php

declare(strict_types=1);

namespace Trunk\Orm\UnitOfWork;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Trunk\Database\Connection\Connection;
use Trunk\Orm\Diagnostics\NPlusOneDetector;
use Trunk\Orm\Diagnostics\NPlusOneFinding;
use Trunk\Orm\Exception\OrmException;
use Trunk\Orm\Exception\RelationNotLoaded;
use Trunk\Orm\Mapping\MappingRegistry;
use Trunk\Orm\Repository\Repository;
use Trunk\Orm\Repository\Scope;

/**
 * The entry point to the ORM. Inject it; there is no static access. One per request or job
 * (scoped in the container), because it owns the identity map and pending changes.
 *
 *   $customers = $manager->repository(Customer::class);
 *   $customer = $customers->find(7);
 *   $customer->name = 'Ada';
 *   $manager->flush();                     // one transaction, only changed columns
 *
 * @api
 */
final class EntityManager
{
    private readonly UnitOfWork $unit;

    /** @var array<class-string, Scope> */
    private array $scopes = [];

    /**
     * @param iterable<Scope>                     $scopes global scopes, matched to maps by class
     * @param (Closure(): DateTimeImmutable)|null $clock  used for soft-delete timestamps
     *
     * @internal wired by the container, not part of the API
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly MappingRegistry $registry,
        iterable $scopes = [],
        ?Closure $clock = null,
    ) {
        foreach ($scopes as $scope) {
            $this->scopes[$scope::class] = $scope;
        }

        $this->unit = new UnitOfWork($connection, $registry, $clock ?? static fn(): DateTimeImmutable => new DateTimeImmutable());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return Repository<T>
     */
    public function repository(string $class): Repository
    {
        return new Repository($this, $class, $this->registry->metadata($class), $this->registry->mapper($class));
    }

    /** Schedules a new entity for insertion at the next flush(). */
    public function persist(object $entity): void
    {
        $this->unit->persist($entity);
    }

    /** Schedules a loaded entity for deletion (a soft delete when the map says so). */
    public function remove(object $entity): void
    {
        $this->unit->remove($entity);
    }

    /** Writes every pending insert, change and removal in one transaction. */
    public function flush(): void
    {
        $this->unit->flush();
    }

    /** Forgets all tracked entities (call after a failed flush, or between units of work in a worker). */
    public function clear(): void
    {
        $this->unit->clear();
    }

    /**
     * A relation loaded with `->with('name')`. Never queries: an unloaded relation is an error.
     *
     * @throws RelationNotLoaded
     */
    public function related(object $entity, string $relation): mixed
    {
        $this->registry->metadata($entity::class)->relation($relation);

        if (!$this->unit->hasRelation($entity, $relation)) {
            throw RelationNotLoaded::for($entity::class, $relation);
        }

        return $this->unit->relation($entity, $relation);
    }

    /**
     * @return list<object>
     */
    public function relatedMany(object $entity, string $relation): array
    {
        $value = $this->related($entity, $relation);

        return \is_array($value) ? array_values(array_filter($value, \is_object(...))) : [];
    }

    public function relatedOne(object $entity, string $relation): ?object
    {
        $value = $this->related($entity, $relation);

        return \is_object($value) ? $value : null;
    }

    /**
     * The mapped, non-hidden properties as plain values (enums and dates become strings), for
     * JSON responses. Unmapped and hidden properties are never included.
     *
     * @return array<string, scalar|array<array-key, mixed>|null>
     */
    public function toArray(object $entity): array
    {
        $metadata = $this->registry->metadata($entity::class);
        $properties = get_object_vars($entity);
        $result = [];

        foreach ($metadata->columns as $property => $column) {
            if ($column->hidden || !\array_key_exists($property, $properties)) {
                continue;
            }

            $value = $properties[$property];
            $result[$property] = match (true) {
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof BackedEnum => $value->value,
                \is_scalar($value), \is_array($value), $value === null => $value,
                default => null,
            };
        }

        return $result;
    }

    /**
     * Statements that ran suspiciously often since the connection's query log was last cleared.
     * Empty unless query logging is on (it is in development).
     *
     * @return list<NPlusOneFinding>
     */
    public function nPlusOneFindings(int $threshold = 5): array
    {
        $log = $this->connection->log();

        return $log === null ? [] : new NPlusOneDetector($threshold)->detect($log);
    }

    /** @internal */
    public function connection(): Connection
    {
        return $this->connection;
    }

    /** @internal */
    public function registry(): MappingRegistry
    {
        return $this->registry;
    }

    /** @internal */
    public function unitOfWork(): UnitOfWork
    {
        return $this->unit;
    }

    /**
     * A declared scope that was not registered fails closed: the query does not run.
     *
     * @internal
     *
     * @param class-string $scope
     */
    public function scope(string $scope): Scope
    {
        return $this->scopes[$scope] ?? throw new OrmException(\sprintf('The map declares scope %s but no such scope service is registered. Register it in the container and tag it "orm.scope".', $scope));
    }
}
