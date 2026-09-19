<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

use Trunk\Database\Exception\InvalidQueryException;

/**
 * The comparison operators the builder accepts; anything else is refused.
 */
final class Operator
{
    private const array ALLOWED = ['=', '<>', '!=', '<', '>', '<=', '>=', 'like', 'not like'];

    public static function normalize(string $operator): string
    {
        $normalized = strtolower(trim($operator));

        if (!\in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidQueryException(\sprintf('"%s" is not a supported operator. Use one of: %s.', mb_substr((string) preg_replace('/[^\x20-\x7E]/', '?', $operator), 0, 30), implode(' ', self::ALLOWED)));
        }

        return strtoupper($normalized);
    }
}
