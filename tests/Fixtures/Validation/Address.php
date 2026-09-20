<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

use Trunk\Validation\Rules\Length;
use Trunk\Validation\Rules\Required;

final readonly class Address
{
    public function __construct(
        #[Required, Length(max: 40)]
        public string $street,
        #[Required]
        public string $city,
    ) {}
}
