<?php

declare(strict_types=1);

namespace Trunk\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Trunk\Error\ErrorCode;
use Trunk\Error\HasErrorCode;

/**
 * @api
 */
final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface, HasErrorCode
{
    public function errorCode(): string
    {
        return ErrorCode::DependencyNotFound->value;
    }

    public static function forId(string $id): self
    {
        return new self(\sprintf('No entry was found for "%s".', $id));
    }
}
