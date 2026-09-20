<?php

declare(strict_types=1);

namespace Trunk\Validation\Rules;

use Attribute;
use InvalidArgumentException;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

/**
 * The number of entries in a list: `#[Items(max: 10)]`, `#[Items(min: 1)]` or both.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Items implements Rule
{
    public function __construct(public ?int $min = null, public ?int $max = null)
    {
        if (($min === null && $max === null) || ($min !== null && $min < 0) || ($max !== null && $min !== null && $max < $min)) {
            throw new InvalidArgumentException('Items needs a min and/or a max, with 0 <= min <= max.');
        }
    }

    public function check(mixed $value, Context $context): ?Violation
    {
        $count = \is_array($value) ? \count($value) : 0;

        return match (true) {
            $this->min !== null && $count < $this->min => new Violation('min_items', \sprintf('Must have at least %d items.', $this->min)),
            $this->max !== null && $count > $this->max => new Violation('max_items', \sprintf('Must have at most %d items.', $this->max)),
            default => null,
        };
    }
}
