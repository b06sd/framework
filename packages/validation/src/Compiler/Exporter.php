<?php

declare(strict_types=1);

namespace Trunk\Validation\Compiler;

use ReflectionClass;
use ReflectionProperty;
use Trunk\Compiler\Exception\CompilationException;
use Trunk\Support\CodeNames;
use UnitEnum;

/**
 * Writes a value back out as PHP source: scalars, arrays, enum cases and objects whose constructor
 * parameters are public properties (rules and plans). It is the only way anything reaches the
 * generated file, so every string goes through `var_export` and every name through `CodeNames`.
 */
final class Exporter
{
    /**
     * @throws CompilationException
     */
    public static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value), \is_int($value) => var_export($value, true),
            \is_float($value) => is_finite($value) ? var_export($value, true) : throw new CompilationException(['A default value of NAN or INF cannot be compiled.']),
            \is_string($value) => var_export($value, true),
            \is_array($value) => '[' . implode(', ', array_map(static fn($k, $v): string => self::export($k) . ' => ' . self::export($v), array_keys($value), $value)) . ']',
            $value instanceof UnitEnum => CodeNames::className($value::class) . '::' . CodeNames::property($value->name),
            \is_object($value) => self::object($value),
            default => throw new CompilationException(['A value of type ' . get_debug_type($value) . ' cannot be compiled.']),
        };
    }

    private static function object(object $value): string
    {
        $class = new ReflectionClass($value);
        $arguments = [];

        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if (!$class->hasProperty($parameter->getName()) || !$class->getProperty($parameter->getName())->isPublic()) {
                throw new CompilationException([\sprintf('%s cannot be compiled: constructor parameter $%s must be a public property (use promoted, public constructor parameters).', $class->getName(), $parameter->getName())]);
            }

            $property = new ReflectionProperty($value, $parameter->getName());
            $current = $property->getValue($value);

            if ($parameter->isVariadic()) {
                foreach (\is_array($current) ? $current : [] as $item) {
                    $arguments[] = self::export($item);
                }

                continue;
            }

            $arguments[] = self::export($current);
        }

        return 'new ' . CodeNames::className($class->getName()) . '(' . implode(', ', $arguments) . ')';
    }
}
