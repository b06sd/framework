<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

use Attribute;
use Trunk\Validation\Context;
use Trunk\Validation\Rule;
use Trunk\Validation\Violation;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Even implements Rule
{
    public function __construct(public int $above = 0) {}

    public function check(mixed $value, Context $context): ?Violation
    {
        return \is_int($value) && $value % 2 === 0 && $value > $this->above ? null : new Violation('even', \sprintf('Must be an even number above %d.', $this->above));
    }
}
