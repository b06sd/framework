<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * A plain ASCII email address of at most 254 characters, with a dotted domain.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Email implements Rule
{
    public function check(mixed $value, Context $context): ?Violation
    {
        $valid = \is_string($value)
            && \strlen($value) <= 254
            && filter_var($value, \FILTER_VALIDATE_EMAIL) !== false
            && strpos($value, '@') <= 64
            && str_contains(substr($value, (int) strrpos($value, '@')), '.');

        return $valid ? null : new Violation('email', 'Must be a valid email address.');
    }
}
