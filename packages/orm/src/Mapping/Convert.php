<?php

declare(strict_types=1);

namespace Trunk\Orm\Mapping;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use Trunk\Orm\Exception\HydrationException;

/**
 * Strict conversions between database values and PHP values. Pure functions with no state. Failure
 * messages name the entity, column and types, never the value.
 */
final class Convert
{
    private const int JSON_DEPTH = 32;

    // ---- database -> PHP (the generated hydrators call these directly) ----

    public static function int(mixed $value, string $entity, string $column): int
    {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && preg_match('/^-?\d{1,18}$/D', $value) === 1) {
            return (int) $value;
        }

        if (\is_float($value) && $value === floor($value) && abs($value) < 9.0e15) {
            return (int) $value;
        }

        throw self::fail($entity, $column, 'int', $value);
    }

    public static function string(mixed $value, string $entity, string $column): string
    {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw self::fail($entity, $column, 'string', $value);
    }

    public static function float(mixed $value, string $entity, string $column): float
    {
        if (\is_float($value)) {
            return $value;
        }

        if (\is_int($value) || (\is_string($value) && is_numeric($value))) {
            return (float) $value;
        }

        throw self::fail($entity, $column, 'float', $value);
    }

    public static function bool(mixed $value, string $entity, string $column): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        return match ($value) {
            1, '1', 't', 'true' => true,
            0, '0', 'f', 'false' => false,
            default => throw self::fail($entity, $column, 'bool', $value),
        };
    }

    public static function dateTime(mixed $value, string $entity, string $column): DateTimeImmutable
    {
        if (\is_string($value)) {
            $utc = new DateTimeZone('UTC');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }

            foreach (['!Y-m-d H:i:s.u', '!Y-m-d'] as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $value, $utc);

                if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                    return $date;
                }
            }

            $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $value, $utc);

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date->setTimezone($utc);
            }
        }

        throw self::fail($entity, $column, 'datetime', $value);
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    public static function enum(mixed $value, string $enum, string $entity, string $column): BackedEnum
    {
        if (\is_string($value) || \is_int($value)) {
            $case = $enum::tryFrom($value);

            if ($case !== null) {
                return $case;
            }
        }

        throw self::fail($entity, $column, 'enum', $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function json(mixed $value, string $entity, string $column): array
    {
        if (\is_string($value)) {
            try {
                $decoded = json_decode($value, true, self::JSON_DEPTH, \JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw self::fail($entity, $column, 'json', $value);
            }

            if (\is_array($decoded)) {
                return $decoded;
            }
        }

        throw self::fail($entity, $column, 'json', $value);
    }

    // ---- PHP -> database ----

    public static function dateTimeToDb(?DateTimeInterface $value): ?string
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @param array<array-key, mixed>|null $value
     */
    public static function jsonToDb(?array $value): ?string
    {
        return $value === null ? null : json_encode($value, \JSON_THROW_ON_ERROR, self::JSON_DEPTH);
    }

    /**
     * The generic path used by the interpreted (development) mapper and by query values.
     */
    public static function toDb(Type $type, mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            Type::Int => \is_int($value) ? $value : self::int($value, 'value', 'value'),
            Type::String => \is_string($value) ? $value : self::string($value, 'value', 'value'),
            Type::Float => \is_float($value) ? $value : self::float($value, 'value', 'value'),
            Type::Bool => \is_bool($value) ? $value : self::bool($value, 'value', 'value'),
            Type::DateTime => $value instanceof DateTimeInterface ? self::dateTimeToDb($value) : throw self::fail('value', 'value', 'datetime', $value),
            Type::Enum => $value instanceof BackedEnum ? $value->value : throw self::fail('value', 'value', 'enum', $value),
            Type::Json => \is_array($value) ? self::jsonToDb($value) : throw self::fail('value', 'value', 'json', $value),
        };
    }

    /**
     * The generic path used by the interpreted (development) mapper.
     *
     * @param class-string<BackedEnum>|null $enum
     */
    public static function toPhp(Type $type, mixed $value, ?string $enum, string $entity, string $column, bool $nullable): mixed
    {
        if ($value === null) {
            return $nullable ? null : throw self::fail($entity, $column, $type->value, null);
        }

        return match ($type) {
            Type::Int => self::int($value, $entity, $column),
            Type::String => self::string($value, $entity, $column),
            Type::Float => self::float($value, $entity, $column),
            Type::Bool => self::bool($value, $entity, $column),
            Type::DateTime => self::dateTime($value, $entity, $column),
            Type::Enum => self::enum($value, $enum ?? throw self::fail($entity, $column, 'enum', $value), $entity, $column),
            Type::Json => self::json($value, $entity, $column),
        };
    }

    public static function nullFailure(string $entity, string $column, string $expected): HydrationException
    {
        return self::fail($entity, $column, $expected, null);
    }

    private static function fail(string $entity, string $column, string $expected, mixed $value): HydrationException
    {
        return new HydrationException(\sprintf('Cannot hydrate %s: column "%s" holds %s but the map says %s.', $entity, $column, $value === null ? 'NULL' : 'a ' . get_debug_type($value), $expected));
    }
}
