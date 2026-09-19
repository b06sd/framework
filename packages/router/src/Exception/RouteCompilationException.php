<?php

declare(strict_types=1);

namespace Trunk\Router\Exception;

use RuntimeException;
use Throwable;

final class RouteCompilationException extends RuntimeException
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors, ?Throwable $previous = null)
    {
        parent::__construct("Route compilation failed:\n - " . implode("\n - ", $errors), previous: $previous);
    }
}
