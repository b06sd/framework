<?php

declare(strict_types=1);

namespace Trunk\OpenApi\Attribute;

use Attribute;
use InvalidArgumentException;

/**
 * Documents a response of a handler: `#[Returns(201, OrderView::class)]`, `#[Returns(200, Line::class,
 * list: true)]`, `#[Returns(204)]`. Repeat it for each status. The class is described from its public
 * properties; Trunk does not check that the handler really returns it.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Returns
{
    /**
     * @param class-string|null $class the JSON body's shape, or null for no body
     * @param bool              $list  the body is a JSON array of `$class`
     */
    public function __construct(
        public int $status,
        public ?string $class = null,
        public bool $list = false,
        public string $description = '',
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(\sprintf('#[Returns(%d)]: a status is between 100 and 599.', $status));
        }

        if ($list && $class === null) {
            throw new InvalidArgumentException('#[Returns(list: true)] needs a class.');
        }
    }
}
