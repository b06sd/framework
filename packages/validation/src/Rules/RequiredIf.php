<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\PresenceRule;
use Trunk\Validation\Violation;

/**
 * The field is required when another field has a given value: `#[RequiredIf('type', 'company')]`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class RequiredIf implements PresenceRule
{
    public function __construct(public string $field, public string|int|float|bool $equals) {}

    public function whenMissing(Context $context): ?Violation
    {
        return $context->sibling($this->field) === $this->equals
            ? new Violation('required_if', \sprintf('This field is required when %s is %s.', $this->field, var_export($this->equals, true)))
            : null;
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        return (\is_string($value) && trim($value) === '') || $value === [] ? $this->whenMissing($context) : null;
    }
}
