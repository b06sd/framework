<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

final class PostgresGrammar extends Grammar
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
        return ($limit === null ? '' : ' LIMIT ' . $limit) . ($offset === null ? '' : ' OFFSET ' . $offset);
    }
}
