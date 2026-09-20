<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\PresenceRule;
use Trunk\Validation\Violation;

/**
 * The field must be sent, and text and lists must not be empty (text is trimmed first).
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Required implements PresenceRule
{
    public function whenMissing(Context $context): Violation
    {
        return new Violation('required', 'This field is required.');
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        $empty = (\is_string($value) && trim($value) === '') || $value === [];

        return $empty ? new Violation('required', 'This field is required.') : null;
    }
}
