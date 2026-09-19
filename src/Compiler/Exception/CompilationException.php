<?php

declare(strict_types=1);

namespace Trunk\Compiler\Exception;

use RuntimeException;
use Trunk\Error\ErrorCode;
use Trunk\Error\HasErrorCode;

/**
 * @api
 */
final class CompilationException extends RuntimeException implements HasErrorCode
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Compilation failed:\n - " . implode("\n - ", $errors));
    }
    public function errorCode(): string
    {
        return ErrorCode::CompilationFailed->value;
    }
}
