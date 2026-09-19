<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use RuntimeException;

/**
 * One or more job classes are invalid. Raised at build time (and on first use in development).
 *
 * @api
 */
final class JobMappingException extends RuntimeException
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Invalid queue jobs:\n - " . implode("\n - ", $errors));
    }
}
