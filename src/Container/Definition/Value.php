<?php

declare(strict_types=1);

namespace Trunk\Container\Definition;

use Trunk\Container\Exception\ContainerException;

/**
 * A literal constructor argument. Only data that can be compiled into source is allowed.
 *
 * @api
 */
final readonly class Value implements Argument
{
    public function __construct(public mixed $value)
    {
        self::assertCompilable($value);
    }

    private static function assertCompilable(mixed $value): void
    {
        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertCompilable($item);
            }

            return;
        }

        if ($value !== null && !\is_scalar($value)) {
            throw new ContainerException(\sprintf('Literal arguments must be scalars, null or arrays of them, %s given.', get_debug_type($value)));
        }
    }
}
