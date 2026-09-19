<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

final class SqliteGrammar extends Grammar
{
    public function supportsReturning(): bool
    {
        return true;
    }

    protected function quoteSegment(string $segment): string
    {
        return '"' . $segment . '"';
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return ' LIMIT ' . ($limit ?? -1) . ($offset === null ? '' : ' OFFSET ' . $offset);
    }
}
