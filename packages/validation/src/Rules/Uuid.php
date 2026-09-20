<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * A UUID in the standard hyphenated form (versions 1 to 8).
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Uuid implements Rule
{
    public function check(mixed $value, Context $context): ?Violation
    {
        $valid = \is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $value) === 1;

        return $valid ? null : new Violation('uuid', 'Must be a valid UUID.');
    }
}
