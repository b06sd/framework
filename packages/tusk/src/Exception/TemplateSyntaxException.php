<?php

declare(strict_types=1);

namespace Trunk\Tusk\Exception;

use RuntimeException;

/**
 * @api
 */
final class TemplateSyntaxException extends RuntimeException
{
    public function __construct(public readonly string $template, public readonly int $templateLine, string $problem)
    {
        parent::__construct(\sprintf('%s:%d: %s', $template, $templateLine, $problem));
    }
}
