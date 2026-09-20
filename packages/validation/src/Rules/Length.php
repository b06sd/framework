<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use InvalidArgumentException;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * Text length in characters (not bytes): `#[Length(min: 3)]`, `#[Length(max: 40)]` or both.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Length implements Rule
{
    public function __construct(public ?int $min = null, public ?int $max = null)
    {
        if (($min === null && $max === null) || ($min !== null && $min < 0) || ($max !== null && $min !== null && $max < $min)) {
            throw new InvalidArgumentException('Length needs a min and/or a max, with 0 <= min <= max.');
        }
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        if (!\is_string($value)) {
            return new Violation('length', 'Must be text.');
        }

        $length = mb_strlen($value, 'UTF-8');

        return match (true) {
            $this->min !== null && $length < $this->min => new Violation('min_length', \sprintf('Must be at least %d characters.', $this->min)),
            $this->max !== null && $length > $this->max => new Violation('max_length', \sprintf('Must be at most %d characters.', $this->max)),
            default => null,
        };
    }
}
