<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

/**
 * Everything a query builder knows, as plain data for the grammar to turn into SQL.
 */
final readonly class QueryState
{
    /**
     * @param list<string|Raw>                                                              $columns
     * @param list<Where>                                                                   $wheres
     * @param list<array{type: string, table: string, first: string, operator: string, second: string}> $joins
     * @param list<string>                                                                  $groups
     * @param list<Where>                                                                   $havings
     * @param list<array{string|Raw, string}>                                               $orders   column and direction
     * @param array{string, string}|null                                                    $aggregate function and column
     */
    public function __construct(
        public string $table,
        public array $columns = ['*'],
        public bool $distinct = false,
        public array $wheres = [],
        public array $joins = [],
        public array $groups = [],
        public array $havings = [],
        public array $orders = [],
        public ?int $limit = null,
        public ?int $offset = null,
        public ?array $aggregate = null,
    ) {}
}
