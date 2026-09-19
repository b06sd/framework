<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use RuntimeException;

/**
 * One or more entity maps are invalid. Raised at build time (and at first use in development),
 * never for user input.
 *
 * @api
 */
final class MappingException extends RuntimeException
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid ORM mapping:\n - " . implode("\n - ", $errors));
    }
}
