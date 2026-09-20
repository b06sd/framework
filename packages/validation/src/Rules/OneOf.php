<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * The value is one of a fixed list: `#[OneOf(['draft', 'published'])]`. Compared strictly. (A backed
 * enum needs no rule: declare the parameter as the enum.)
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class OneOf implements Rule
{
    /**
     * @param list<string|int|float|bool> $values
     */
    public function __construct(public array $values) {}

    public function check(mixed $value, Context $context): ?Violation
    {
        return \in_array($value, $this->values, true) ? null : new Violation('one_of', 'Must be one of the allowed values.');
    }
}
