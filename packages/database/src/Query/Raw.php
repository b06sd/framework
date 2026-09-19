<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

/**
 * SQL that is inserted as written. This is the one deliberate escape hatch of the query builder:
 * never put user input in `$sql` (use `$bindings`, which are bound safely).
 *
 * @api
 */
final readonly class Raw
{
    /**
     * @param list<scalar|null> $bindings
     */
    public function __construct(public string $sql, public array $bindings = []) {}
}
