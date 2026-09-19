<?php

declare(strict_types=1);

namespace Trunk\Tusk;

/**
 * Template names are logical paths ("users/index"), never filesystem paths.
 */
final class TemplateName
{
    public static function isValid(string $name): bool
    {
        return \strlen($name) <= 200 && preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D', $name) === 1;
    }

    public static function isVariable(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) === 1;
    }
}
