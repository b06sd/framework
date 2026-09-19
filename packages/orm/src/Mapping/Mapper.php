<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

/**
 * Moves one entity class between database rows and objects. The compiled implementation is
 * generated code; the interpreted one is the development fallback with identical behaviour.
 */
interface Mapper
{
    /**
     * @param array<string, mixed> $row column => value as returned by the driver
     */
    public function hydrate(array $row): object;

    /**
     * @return array<string, string|int|float|bool|null> column => database value, every mapped column
     */
    public function extract(object $entity): array;

    /**
     * Stores the generated id on a newly inserted entity.
     */
    public function assignId(object $entity, int|string $id): void;
}
