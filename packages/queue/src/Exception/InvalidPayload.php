<?php

declare(strict_types=1);

namespace Trunk\Queue\Exception;

use RuntimeException;
use Trunk\Error\ErrorCode;
use Trunk\Error\HasErrorCode;

/**
 * A job payload could not be encoded or decoded. Messages name the job and field, never the value.
 *
 * @api
 */
final class InvalidPayload extends RuntimeException implements HasErrorCode, PermanentFailure
{
    public function errorCode(): string
    {
        return ErrorCode::PayloadInvalid->value;
    }
}
