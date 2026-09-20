<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * Equal to another field of the same request: `#[SameAs('password')]` on a confirmation.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class SameAs implements Rule
{
    public function __construct(public string $field) {}

    public function check(mixed $value, Context $context): ?Violation
    {
        $other = $context->sibling($this->field);
        $same = \is_string($value) && \is_string($other) ? hash_equals($other, $value) : $value === $other;

        return $same ? null : new Violation('same_as', \sprintf('Must match %s.', $this->field));
    }
}
