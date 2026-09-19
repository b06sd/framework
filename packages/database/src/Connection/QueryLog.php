<?php

declare(strict_types=1);

namespace Trunk\Database\Connection;

/**
 * A bounded record of recent queries (SQL text, number of bindings, duration). Bound values are
 * never recorded. Meant for development tooling such as N+1 detection.
 */
final class QueryLog
{
    private const int LIMIT = 500;

    /** @var list<array{sql: string, bindings: int, milliseconds: float}> */
    private array $entries = [];

    public function record(string $sql, int $bindings, float $milliseconds): void
    {
        $this->entries[] = ['sql' => $sql, 'bindings' => $bindings, 'milliseconds' => $milliseconds];

        if (\count($this->entries) > self::LIMIT) {
            array_shift($this->entries);
        }
    }

    /**
     * @return list<array{sql: string, bindings: int, milliseconds: float}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return \count($this->entries);
    }

    public function clear(): void
    {
        $this->entries = [];
    }
}
