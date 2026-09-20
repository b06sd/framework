<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use InvalidArgumentException;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * A number within limits: `#[Range(min: 13)]`, `#[Range(max: 120)]` or `#[Range(1, 5)]`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Range implements Rule
{
    public function __construct(public int|float|null $min = null, public int|float|null $max = null)
    {
        if (($min === null && $max === null) || ($min !== null && $max !== null && $max < $min)) {
            throw new InvalidArgumentException('Range needs a min and/or a max, with min <= max.');
        }
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        if (!\is_int($value) && !\is_float($value)) {
            return new Violation('range', 'Must be a number.');
        }

        return match (true) {
            $this->min !== null && $value < $this->min => new Violation('min', \sprintf('Must be at least %s.', $this->min)),
            $this->max !== null && $value > $this->max => new Violation('max', \sprintf('Must be at most %s.', $this->max)),
            default => null,
        };
    }
}
