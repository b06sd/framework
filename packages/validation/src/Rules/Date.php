<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use DateTimeImmutable;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * A real calendar date or time in an exact format (`Y-m-d` unless you say otherwise): `2026-02-30` fails.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Date implements Rule
{
    public function __construct(public string $format = 'Y-m-d') {}

    public function check(mixed $value, Context $context): ?Violation
    {
        $date = \is_string($value) ? DateTimeImmutable::createFromFormat('!' . $this->format, $value) : false;
        $valid = $date !== false && $date->format($this->format) === $value;

        return $valid ? null : new Violation('date', \sprintf('Must be a valid date (%s).', $this->format));
    }
}
