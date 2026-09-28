<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

use Trunk\Validation\Attribute\From;
use Trunk\Validation\Rules\Required;
use Trunk\Validation\Source;

#[From(Source::Json)]
final readonly class JsonOnly
{
    public function __construct(#[Required] public string $name) {}
}
