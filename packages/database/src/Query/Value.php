<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

use DateTimeInterface;
use Trunk\Database\Exception\InvalidQueryException;

/**
 * The only kinds of value that may be bound: scalars, null and dates (formatted as `Y-m-d H:i:s`).
 */
final class Value
{
    public static function normalize(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, \is_scalar($value) => $value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => throw new InvalidQueryException(\sprintf('Only scalars, null and dates can be used as query values, %s given.', get_debug_type($value))),
        };
    }
}
