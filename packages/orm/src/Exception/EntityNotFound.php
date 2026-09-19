<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use RuntimeException;

/**
 * @api
 */
final class EntityNotFound extends RuntimeException
{
    public static function for(string $entity): self
    {
        return new self(\sprintf('No %s was found.', $entity));
    }
}
