<?php

declare(strict_types=1);

namespace Trunk\Foundation\Exception;

use RuntimeException;
use Trunk\Error\ErrorCode;
use Trunk\Error\HasErrorCode;

/**
 * @api
 */
final class ConfigurationException extends RuntimeException implements HasErrorCode
{
    public function errorCode(): string
    {
        return ErrorCode::ConfigurationInvalid->value;
    }
}
