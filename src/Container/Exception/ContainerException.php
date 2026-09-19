<?php

declare(strict_types=1);

namespace Trunk\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;
use Trunk\Error\DeveloperHint;
use Trunk\Error\ErrorCode;
use Trunk\Error\HasErrorCode;

/**
 * @api
 */
final class ContainerException extends RuntimeException implements ContainerExceptionInterface, DeveloperHint, HasErrorCode
{
    public function errorCode(): string
    {
        return ErrorCode::DependencyNotFound->value;
    }

    /**
     * The "Fix: ..." part of the message, when it has one.
     */
    public function hint(): ?string
    {
        $position = strpos($this->getMessage(), 'Fix: ');

        return $position === false ? null : substr($this->getMessage(), $position + 5);
    }
}
