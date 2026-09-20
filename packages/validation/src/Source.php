<?php

declare(strict_types=1);

namespace Trunk\Validation;

/**
 * Where the input came from. JSON keeps its types; form and query values arrive as text, so numbers
 * and booleans are read from strings (strictly) before the rules run.
 *
 * @api
 */
enum Source: string
{
    case Json = 'json';
    case Form = 'form';
    case Query = 'query';

    public function isText(): bool
    {
        return $this !== self::Json;
    }
}
