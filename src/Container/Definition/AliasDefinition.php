<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

/**
 * `$id` resolves to whatever `$target` resolves to (and shares its lifetime).
 */
final readonly class AliasDefinition implements Definition
{
    public function __construct(public string $id, public string $target) {}
}
