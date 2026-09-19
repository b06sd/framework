<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

final class MySqlGrammar extends Grammar
{
    protected function quoteSegment(string $segment): string
    {
        return '`' . $segment . '`';
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        // MySQL has no OFFSET without LIMIT; this is its documented "no limit" value.
        return ' LIMIT ' . ($limit ?? '18446744073709551615') . ($offset === null ? '' : ' OFFSET ' . $offset);
    }

    protected function emptyInsert(): string
    {
        return ' () VALUES ()';
    }
}
