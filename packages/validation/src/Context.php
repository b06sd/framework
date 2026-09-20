<?php

declare(strict_types=1);

namespace Trunk\Validation;

/**
 * What a rule may know besides the value: the field's name and the other raw values submitted with it.
 *
 * @api
 */
final readonly class Context
{
    /**
     * @param array<array-key, mixed> $siblings
     */
    public function __construct(public string $field, private array $siblings = []) {}

    public function sibling(string $name): mixed
    {
        return $this->siblings[$name] ?? null;
    }
}
