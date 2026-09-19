<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

use Trunk\Database\Exception\InvalidQueryException;

/**
 * Validates table and column names. Anything that is not a plain SQL identifier (optionally
 * qualified as `table.column`, `table.*` or aliased as `name as alias`) is refused before it can
 * reach a query, so an identifier can never carry SQL.
 */
final class Identifier
{
    private const string SEGMENT = '[A-Za-z_][A-Za-z0-9_]{0,63}';

    public static function assert(string $name): void
    {
        $segment = self::SEGMENT;

        if (preg_match('/^(?:\*|' . $segment . '(?:\.(?:' . $segment . '|\*))*(?:\s+as\s+' . $segment . ')?)$/Di', $name) !== 1) {
            throw new InvalidQueryException(\sprintf('"%s" is not a valid table or column name (letters, digits and underscores, optionally table.column or "name as alias"). Use Raw for expressions.', self::printable($name)));
        }
    }

    /**
     * A column reference: optionally qualified (`users.name`), but no wildcard and no alias.
     */
    public static function assertColumn(string $name): void
    {
        $segment = self::SEGMENT;

        if (preg_match('/^' . $segment . '(?:\.' . $segment . ')?$/D', $name) !== 1) {
            throw new InvalidQueryException(\sprintf('"%s" is not a valid column name (letters, digits and underscores, optionally table.column).', self::printable($name)));
        }
    }

    /**
     * A bare column or table name: no dots, aliases or wildcards (used where a list of names is written, such as INSERT columns).
     */
    public static function assertSimple(string $name): void
    {
        if (preg_match('/^' . self::SEGMENT . '$/D', $name) !== 1) {
            throw new InvalidQueryException(\sprintf('"%s" is not a valid column name (letters, digits and underscores only).', self::printable($name)));
        }
    }

    public static function isValid(string $name): bool
    {
        try {
            self::assert($name);

            return true;
        } catch (InvalidQueryException) {
            return false;
        }
    }

    private static function printable(string $value): string
    {
        return mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $value), 0, 60);
    }
}
