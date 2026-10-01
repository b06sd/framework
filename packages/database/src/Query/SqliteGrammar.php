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

    /**
     * SQLite has no row locks: one writer holds the whole database for its transaction, and a second
     * transaction that tries to write after reading fails with "database is locked" rather than acting
     * on stale data. So there is nothing to add.
     */
    protected function compileLockForUpdate(): string
    {
        return '';
    }

    protected function compileLimitOffset(?int $limit, ?int $offset): string
    {
        if ($limit === null && $offset === null) {
            return '';
        }

        return ' LIMIT ' . ($limit ?? -1) . ($offset === null ? '' : ' OFFSET ' . $offset);
    }
}
