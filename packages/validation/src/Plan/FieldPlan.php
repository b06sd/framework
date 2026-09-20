<?php

declare(strict_types=1);

namespace Trunk\Validation\Plan;

use Trunk\Validation\Rule;

/**
 * Everything the engine needs to know about one constructor parameter. Every constructor parameter
 * is a public property, because the build writes this class back out as a `new` expression.
 */
final readonly class FieldPlan
{
    /**
     * @param 'string'|'int'|'float'|'bool'|'array'|'enum'|'object' $kind
     * @param class-string|null                                    $class     the enum or request class of an `enum`/`object` field, or the item class of a list
     * @param list<Rule>                                           $rules
     */
    public function __construct(
        public string $name,
        public string $kind,
        public ?string $class,
        public bool $nullable,
        public bool $hasDefault,
        public mixed $default,
        public array $rules,
        public bool $sensitive,
    ) {}
}
