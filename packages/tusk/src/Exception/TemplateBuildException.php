<?php

declare(strict_types=1);

namespace Trunk\Tusk\Exception;

use RuntimeException;

final class TemplateBuildException extends RuntimeException
{
    /**
     * @param non-empty-list<string> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct("Template build failed:\n - " . implode("\n - ", $errors));
    }
}
