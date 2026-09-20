<?php

declare(strict_types=1);

namespace Trunk\Orm\Exception;

use InvalidArgumentException;
use Trunk\Error\ErrorCode;
use Trunk\Error\PublicError;

/**
 * Untrusted query input (a filter or sort from a request) was not acceptable. Never echoes the value.
 * Reaching the error pipeline it is a 400 with a fixed, generic message: the detail in `getMessage()`
 * is for logs and the debug page, not for the client.
 *
 * @api
 */
final class InvalidFilter extends InvalidArgumentException implements PublicError
{
    public function errorCode(): string
    {
        return ErrorCode::BadRequest->value;
    }

    public function statusCode(): int
    {
        return 400;
    }

    public function publicMessage(): string
    {
        return 'The filter or sort is not valid.';
    }

    public function details(): array
    {
        return [];
    }
}
