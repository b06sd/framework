<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

use Trunk\Validation\Rules\Length;
use Trunk\Validation\Rules\Range;
use Trunk\Validation\Rules\Required;

/**
 * Deliberately not listed in validation.requests: the build finds it through `#[Body]`.
 */
final readonly class Order
{
    public function __construct(
        #[Required, Length(max: 40)]
        public string $sku,
        #[Range(1, 99)]
        public int $quantity = 1,
    ) {}
}
