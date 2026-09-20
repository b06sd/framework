<?php

declare(strict_types=1);

namespace Trunk\Validation\Attribute;

use Attribute;

/**
 * Declares that an `array` parameter is a list of request objects: `#[ListOf(LineItem::class)]`.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ListOf
{
    /**
     * @param class-string $class
     */
    public function __construct(public string $class) {}
}
