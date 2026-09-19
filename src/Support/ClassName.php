<?php

declare(strict_types=1);

namespace Trunk\Support;

/**
 * Guards autoloading: a string must look like a class name before it reaches class_exists()
 * or is_subclass_of(), because autoloaders map names to file paths.
 */
final class ClassName
{
    private const string PATTERN = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D';

    public static function isValid(string $name): bool
    {
        return preg_match(self::PATTERN, $name) === 1;
    }
}
