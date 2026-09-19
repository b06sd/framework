<?php

declare(strict_types=1);

namespace Trunk\Support;

use InvalidArgumentException;

/**
 * The only names allowed into a code position of generated PHP (shared by every package that
 * generates code at build time). They take plain strings on
 * purpose, so tests can prove a hostile name is refused before it can reach the generated file.
 */
final class CodeNames
{
    /**
     * @return non-empty-string a fully qualified class name with a leading backslash
     */
    public static function className(string $class): string
    {
        if (!ClassName::isValid($class)) {
            throw new InvalidArgumentException(\sprintf('Refusing to generate code for the invalid class name "%s".', self::printable($class)));
        }

        return '\\' . ltrim($class, '\\');
    }

    /**
     * @return non-empty-string
     */
    public static function property(string $property): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $property) !== 1) {
            throw new InvalidArgumentException(\sprintf('Refusing to generate code for the invalid property name "%s".', self::printable($property)));
        }

        return $property;
    }

    private static function printable(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9_.\\\\\-, ]/', '?', substr($value, 0, 60)) ?? '?';
    }
}
