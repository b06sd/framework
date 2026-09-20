<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use InvalidArgumentException;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * Text that matches a regular expression: `#[Pattern('/^[a-z0-9-]+$/D', 'Use lowercase letters, digits and hyphens.')]`.
 * The pattern is checked when the class is loaded, so a typo fails the build, not a request. Write
 * patterns without nested quantifiers on untrusted input, and end them with `D`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Pattern implements Rule
{
    public function __construct(public string $regex, public string $message = 'Has an invalid format.')
    {
        set_error_handler(static fn(): bool => true);

        try {
            $valid = preg_match($regex, '') !== false;
        } finally {
            restore_error_handler();
        }

        if (!$valid) {
            throw new InvalidArgumentException('Pattern is not a valid regular expression.');
        }
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        return \is_string($value) && preg_match($this->regex, $value) === 1 ? null : new Violation('pattern', $this->message);
    }
}
