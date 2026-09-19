<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

/**
 * A constructor argument resolved from the container. `$parameter` is only used to make errors specific.
 *
 * @api
 */
final readonly class Reference implements Argument
{
    public function __construct(public string $id, public ?string $parameter = null) {}
}
