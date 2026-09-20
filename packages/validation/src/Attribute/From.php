<?php

declare(strict_types=1);

namespace Trunk\Validation\Attribute;

use Attribute;
use Trunk\Validation\Source;

/**
 * Says where a request class is read from, when the content type is not enough: `#[From(Source::Query)]`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class From
{
    public function __construct(public Source $source) {}
}
