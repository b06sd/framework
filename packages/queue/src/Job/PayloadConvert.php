<?php

declare(strict_types=1);

namespace Trunk\Queue\Job;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Trunk\Queue\Exception\InvalidPayload;

/**
 * Strict payload conversions, used by generated and interpreted codecs alike. Errors name the
 * job and field, never the value.
 */
final class PayloadConvert
{
    private const string DATE_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    public static function int(mixed $value, string $job, string $field): int
    {
        return \is_int($value) ? $value : throw self::fail($job, $field, 'int', $value);
    }

    public static function float(mixed $value, string $job, string $field): float
    {
        return match (true) {
            \is_float($value) => $value,
            \is_int($value) => (float) $value,
            default => throw self::fail($job, $field, 'float', $value),
        };
    }

    public static function string(mixed $value, string $job, string $field): string
    {
        return \is_string($value) ? $value : throw self::fail($job, $field, 'string', $value);
    }

    public static function bool(mixed $value, string $job, string $field): bool
    {
        return \is_bool($value) ? $value : throw self::fail($job, $field, 'bool', $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value, string $job, string $field): array
    {
        return \is_array($value) ? $value : throw self::fail($job, $field, 'array', $value);
    }

    public static function dateTime(mixed $value, string $job, string $field): DateTimeImmutable
    {
        if (\is_string($value)) {
            $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, new DateTimeZone('UTC'));

            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return $date;
            }
        }

        throw self::fail($job, $field, 'datetime', $value);
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    public static function enum(mixed $value, string $enum, string $job, string $field): BackedEnum
    {
        if (\is_string($value) || \is_int($value)) {
            $case = $enum::tryFrom($value);

            if ($case !== null) {
                return $case;
            }
        }

        throw self::fail($job, $field, 'enum', $value);
    }

    public static function dateTimeToPayload(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    /**
     * Arrays must hold only scalars, null and nested arrays, at a bounded depth.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    public static function arrayToPayload(array $value, string $job, string $field, int $depth = 0): array
    {
        if ($depth > 8) {
            throw new InvalidPayload(\sprintf('Cannot encode job %s: field "%s" is nested too deeply.', $job, $field));
        }

        foreach ($value as $item) {
            if (\is_array($item)) {
                self::arrayToPayload($item, $job, $field, $depth + 1);
            } elseif (!\is_scalar($item) && $item !== null) {
                throw new InvalidPayload(\sprintf('Cannot encode job %s: field "%s" holds a %s. Arrays may only contain scalars and arrays; pass ids instead of objects.', $job, $field, get_debug_type($item)));
            }
        }

        return $value;
    }

    public static function missing(string $job, string $field): InvalidPayload
    {
        return new InvalidPayload(\sprintf('Cannot decode job %s: field "%s" is missing.', $job, $field));
    }

    public static function unexpected(string $job): InvalidPayload
    {
        return new InvalidPayload(\sprintf('Cannot decode job %s: the payload has a field that the job does not declare.', $job));
    }

    public static function nullFailure(string $job, string $field, string $expected): InvalidPayload
    {
        return self::fail($job, $field, $expected, null);
    }

    private static function fail(string $job, string $field, string $expected, mixed $value): InvalidPayload
    {
        return new InvalidPayload(\sprintf('Cannot decode job %s: field "%s" must be %s but is %s.', $job, $field, $expected, $value === null ? 'null' : 'a ' . get_debug_type($value)));
    }
}
