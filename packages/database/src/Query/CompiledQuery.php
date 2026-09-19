<?php

declare(strict_types=1);

namespace Trunk\Database\Query;

final readonly class CompiledQuery
{
    /**
     * @param list<scalar|null> $bindings positional bindings, in the order of the `?` placeholders
     */
    public function __construct(public string $sql, public array $bindings) {}
}
