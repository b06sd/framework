<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use BackedEnum;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use Throwable;
use Trunk\Database\Exception\InvalidQueryException;
use Trunk\Database\Query\Identifier;
use Trunk\Orm\Exception\MappingException;
use Trunk\Orm\Repository\Scope;
use Trunk\Support\ClassName;

/**
 * Turns EntityMap classes into validated, immutable EntityMetadata. This is the only place that
 * reflects on entities, and it runs at build time (or once per process in development), so the
 * production runtime never reflects. Every problem is collected and reported together, with a fix.
 */
final class MetadataFactory
{
    /**
     * @param list<string> $mapClasses
     *
     * @return array<class-string, EntityMetadata>
     *
     * @throws MappingException
     */
    public function fromClasses(array $mapClasses): array
    {
        $errors = [];
        $maps = [];

        foreach ($mapClasses as $class) {
            if (!ClassName::isValid($class) || !class_exists($class)) {
                $errors[] = \sprintf('orm.maps lists "%s", which is not a class. Check the name and run `composer dump-autoload`.', self::printable($class));

                continue;
            }

            if (!is_subclass_of($class, EntityMap::class)) {
                $errors[] = \sprintf('%s must implement %s.', $class, EntityMap::class);

                continue;
            }

            try {
                $maps[] = new $class();
            } catch (Throwable) {
                $errors[] = \sprintf('%s could not be created: an EntityMap needs a constructor without required arguments.', $class);
            }
        }

        return $this->build($maps, $errors);
    }

    /**
     * @param list<EntityMap> $maps
     * @param list<string>    $errors
     *
     * @return array<class-string, EntityMetadata>
     *
     * @throws MappingException
     */
    public function build(array $maps, array $errors = []): array
    {
        $partial = [];

        foreach ($maps as $map) {
            $entity = $map->entity();

            if (isset($partial[$entity])) {
                $errors[] = \sprintf('%s is mapped twice.', $entity);

                continue;
            }

            $builder = new MapBuilder();
            $map->define($builder);
            $problems = $this->validate($entity, $builder);

            if ($problems !== []) {
                array_push($errors, ...$problems);

                continue;
            }

            $partial[$entity] = $builder;
        }

        $metadata = [];

        foreach ($partial as $entity => $builder) {
            $resolved = $this->resolve($entity, $builder, $partial, $errors);

            if ($resolved !== null) {
                $metadata[$entity] = $resolved;
            }
        }

        if ($errors !== []) {
            throw new MappingException($errors);
        }

        return $metadata;
    }

    /**
     * @param class-string $entity
     *
     * @return list<string>
     */
    private function validate(string $entity, MapBuilder $builder): array
    {
        $errors = array_map(static fn(string $e): string => $entity . ': ' . $e, $builder->errors);

        if (!ClassName::isValid($entity) || !class_exists($entity)) {
            return [\sprintf('%s: the mapped entity is not an existing class.', self::printable($entity))];
        }

        $where = static fn(string $m): string => $entity . ': ' . $m;

        if ($builder->table === null || !self::valid($builder->table)) {
            $errors[] = $where('table() is missing or is not a plain SQL name (letters, digits, underscores).');
        }

        if ($builder->id === null) {
            $errors[] = $where('declare the primary key with id().');

            return $errors;
        }

        if (!\in_array($builder->id->type, [Type::Int, Type::String], true)) {
            $errors[] = $where('the id must be an int or a string.');
        }

        $all = [$builder->id, ...$builder->columns];
        $properties = [];
        $columns = [];

        foreach ($all as $spec) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $spec->property) !== 1) {
                $errors[] = $where(\sprintf('"%s" is not a valid property name.', self::printable($spec->property)));
            }

            if (!self::valid($spec->column)) {
                $errors[] = $where(\sprintf('column "%s" is not a plain SQL name.', self::printable($spec->column)));
            }

            if (isset($properties[$spec->property])) {
                $errors[] = $where(\sprintf('property "%s" is mapped twice.', $spec->property));
            }

            if (isset($columns[$spec->column])) {
                $errors[] = $where(\sprintf('column "%s" is mapped twice.', $spec->column));
            }

            $properties[$spec->property] = true;
            $columns[$spec->column] = true;

            if ($spec->type === Type::Enum && ($spec->enum === null || !enum_exists($spec->enum) || !is_subclass_of($spec->enum, BackedEnum::class))) {
                $errors[] = $where(\sprintf('"%s" must be a backed enum.', $spec->property));
            }
        }

        foreach ($builder->relations as $relation) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $relation->name) !== 1 || isset($properties[$relation->name])) {
                $errors[] = $where(\sprintf('relation name "%s" is invalid or clashes with a mapped property.', self::printable($relation->name)));
            }

            foreach ([$relation->pivotTable, $relation->pivotLocalColumn, $relation->pivotForeignColumn] as $name) {
                if ($name !== null && !self::valid($name)) {
                    $errors[] = $where(\sprintf('pivot name "%s" is not a plain SQL name.', self::printable($name)));
                }
            }
        }

        foreach ($builder->scopes as $scope) {
            if (!ClassName::isValid($scope) || !is_subclass_of($scope, Scope::class)) {
                $errors[] = $where(\sprintf('scope "%s" must be a class implementing %s.', self::printable($scope), Scope::class));
            }
        }

        foreach ($all as $spec) {
            if ($spec->hidden && ($spec === $builder->id || $spec->property === $builder->versionProperty || $spec->property === $builder->softDeleteProperty)) {
                $errors[] = $where(\sprintf('"%s" is needed by the ORM (id, version or soft delete) and cannot be hidden().', $spec->property));
            }
        }

        if ($builder->versionProperty !== null && $builder->id->property === $builder->versionProperty) {
            $errors[] = $where('the version cannot be the id.');
        }

        return [...$errors, ...$this->reflect($entity, $builder, $all)];
    }

    /**
     * @param class-string     $entity
     * @param list<ColumnSpec> $all
     *
     * @return list<string>
     */
    private function reflect(string $entity, MapBuilder $builder, array $all): array
    {
        $errors = [];
        $where = static fn(string $m): string => $entity . ': ' . $m;

        $reflection = new ReflectionClass($entity);

        $constructor = $reflection->getConstructor();

        if (!$reflection->isInstantiable() || $constructor === null || !$constructor->isPublic()) {
            return [$where('needs a public constructor: entities are built through their constructor, never by setting properties.')];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter;
        }

        foreach ($all as $spec) {
            if (!$reflection->hasProperty($spec->property)) {
                $errors[] = $where(\sprintf('has no property "%s".', $spec->property));

                continue;
            }

            $property = $reflection->getProperty($spec->property);

            if (!$property->isPublic()) {
                $errors[] = $where(\sprintf('property "%s" must be publicly readable (public, or `public private(set)`).', $spec->property));
            }

            if (!isset($parameters[$spec->property])) {
                $errors[] = $where(\sprintf('needs a constructor parameter named "%s" (mapped properties are hydrated through the constructor).', $spec->property));
            }

            if ($builder->id === $spec && $builder->generatedId) {
                if ($property->isReadOnly() || $property->getType()?->allowsNull() !== true) {
                    $errors[] = $where(\sprintf('a generated id "%s" must be a non-readonly nullable property, e.g. `public private(set) ?int $%s = null`.', $spec->property, $spec->property));
                }

                if (isset($parameters[$spec->property]) && !$parameters[$spec->property]->isOptional() && $parameters[$spec->property]->getType()?->allowsNull() !== true) {
                    $errors[] = $where(\sprintf('the constructor parameter "%s" must accept null for a new entity.', $spec->property));
                }
            }

            if ($spec->hidden && isset($parameters[$spec->property]) && !$parameters[$spec->property]->isOptional()) {
                $errors[] = $where(\sprintf('hidden "%s" is not selected, so its constructor parameter needs a default value (e.g. `public string $%s = \'\'`).', $spec->property, $spec->property));
            }

            if ($spec->nullable && $property->getType()?->allowsNull() !== true) {
                $errors[] = $where(\sprintf('"%s" is mapped nullable() but its property type does not allow null.', $spec->property));
            }

            if (!$spec->nullable && $builder->id !== $spec && $property->getType() instanceof ReflectionNamedType && $property->getType()->allowsNull() && $property->getType()->getName() !== 'mixed') {
                $errors[] = $where(\sprintf('"%s" can be null in PHP but the map does not say ->nullable().', $spec->property));
            }
        }

        $mapped = array_flip(array_map(static fn(ColumnSpec $s): string => $s->property, $all));

        foreach ($parameters as $name => $parameter) {
            if (!isset($mapped[$name]) && !self::hasDefault($parameter)) {
                $errors[] = $where(\sprintf('constructor parameter "%s" has no default and is not mapped, so it cannot be hydrated.', $name));
            }
        }

        return $errors;
    }

    /**
     * @param class-string                    $entity
     * @param array<class-string, MapBuilder> $all
     * @param list<string>                    $errors
     */
    private function resolve(string $entity, MapBuilder $builder, array $all, array &$errors): ?EntityMetadata
    {
        $id = $builder->id;
        $table = $builder->table;

        if ($id === null || $table === null) {
            return null;
        }

        $columns = [];

        foreach ([$id, ...$builder->columns] as $spec) {
            $columns[$spec->property] = new ColumnMetadata($spec->property, $spec->column, $spec->type, $spec->nullable, $spec->hidden, $spec->filterable, $spec->sortable, $spec->enum);
        }

        $relations = [];
        $before = \count($errors);

        foreach ($builder->relations as $relation) {
            $targetMap = $all[$relation->target] ?? null;

            if ($targetMap === null || $targetMap->id === null) {
                $errors[] = \sprintf('%s: relation "%s" targets %s, which has no entity map (add its map to orm.maps).', $entity, $relation->name, self::printable($relation->target));

                continue;
            }

            $relations[$relation->name] = match ($relation->kind) {
                RelationKind::HasOne, RelationKind::HasMany => $this->keyed($entity, $relation, $builder, $targetMap, $errors, foreignOnTarget: true),
                RelationKind::BelongsTo => $this->keyed($entity, $relation, $builder, $targetMap, $errors, foreignOnTarget: false),
                RelationKind::BelongsToMany => new RelationMetadata($relation->name, $relation->kind, $relation->target, $id->column, $targetMap->id->column, $relation->pivotTable, $relation->pivotLocalColumn, $relation->pivotForeignColumn),
            };
        }

        if (\count($errors) > $before) {
            return null;
        }

        return new EntityMetadata(
            $entity,
            $table,
            $id->property,
            $builder->generatedId,
            $columns,
            $relations,
            $builder->softDeleteProperty,
            $builder->versionProperty,
            $builder->scopes,
            array_values(array_map(static fn(ColumnMetadata $c): string => $c->column, array_filter($columns, static fn(ColumnMetadata $c): bool => !$c->hidden))),
        );
    }

    /**
     * @param list<string> $errors
     */
    private function keyed(string $entity, RelationSpec $relation, MapBuilder $own, MapBuilder $target, array &$errors, bool $foreignOnTarget): RelationMetadata
    {
        $ownColumns = self::columnsOf($own);
        $targetColumns = self::columnsOf($target);
        $foreign = $relation->foreignProperty ?? '';
        $local = $relation->localProperty;

        if ($foreignOnTarget) {
            $localColumn = $local === null ? $own->id?->column : ($ownColumns[$local] ?? null);
            $foreignColumn = $targetColumns[$foreign] ?? null;
        } else {
            $foreignColumn = $ownColumns[$foreign] ?? null;
            $localColumn = $local === null ? $target->id?->column : ($targetColumns[$local] ?? null);
        }

        if ($localColumn === null || $foreignColumn === null) {
            $errors[] = \sprintf('%s: relation "%s" names a key property that is not mapped (%s).', $entity, $relation->name, self::printable($foreign . ($local === null ? '' : ', ' . $local)));

            return new RelationMetadata($relation->name, $relation->kind, $relation->target, 'id', 'id');
        }

        return new RelationMetadata($relation->name, $relation->kind, $relation->target, $localColumn, $foreignColumn);
    }

    /**
     * @return array<string, string> property => column
     */
    private static function columnsOf(MapBuilder $builder): array
    {
        $columns = [];

        foreach ([$builder->id, ...$builder->columns] as $spec) {
            if ($spec !== null) {
                $columns[$spec->property] = $spec->column;
            }
        }

        return $columns;
    }

    private static function hasDefault(ReflectionParameter $parameter): bool
    {
        return $parameter->isOptional() || $parameter->isDefaultValueAvailable();
    }

    private static function valid(string $name): bool
    {
        try {
            Identifier::assertSimple($name);

            return true;
        } catch (InvalidQueryException) {
            return false;
        }
    }

    private static function printable(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.\\\\\-, ]/', '?', substr($value, 0, 60)) ?? '?';
    }
}
