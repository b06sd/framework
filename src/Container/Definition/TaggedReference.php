<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

use Trunk\Container\Exception\ContainerException;

/**
 * A constructor argument that receives every service registered under a tag, as an array in
 * registration order.
 *
 * @api
 */
final readonly class TaggedReference implements Argument
{
    public function __construct(public string $tag)
    {
        if (preg_match('/^[A-Za-z0-9_.\-]+$/D', $tag) !== 1) {
            throw new ContainerException(\sprintf('"%s" is not a valid tag name.', $tag));
        }
    }
}
