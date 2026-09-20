<?php

declare(strict_types=1);

namespace Trunk\Tests\Fixtures\Validation;

use Trunk\Validation\Attribute\From;
use Trunk\Validation\Rules\Length;
use Trunk\Validation\Rules\Range;
use Trunk\Validation\Source;

#[From(Source::Query)]
final readonly class SearchRequest
{
    public function __construct(
        #[Range(1, 1000)]
        public int $page = 1,
        #[Length(max: 50)]
        public ?string $q = null,
        public bool $archived = false,
    ) {}
}
